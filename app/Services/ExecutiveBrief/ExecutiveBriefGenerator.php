<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\ExecutiveBriefStatus;
use App\Enums\ExecutiveBriefType;
use App\Enums\JarvisNotificationType;
use App\Enums\OwnerLocale;
use App\Models\ChannelIdentity;
use App\Models\ExecutiveBrief;
use App\Models\User;
use App\Services\Automation\AutomationExecutor;
use App\Services\Automation\AutomationResult;
use App\Services\Automation\AutomationRunKey;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ExecutiveBriefGenerator
{
    public function __construct(
        private readonly ExecutiveBriefCollector $collector,
        private readonly ExecutiveBriefAssembler $assembler,
        private readonly ExecutiveBriefComposer $composer,
        private readonly ExecutiveBriefTelegramRenderer $telegramRenderer,
        private readonly OwnerLocaleResolver $locales,
        private readonly JarvisNotificationService $inbox,
        private readonly AutomationExecutor $executor = new AutomationExecutor,
        private readonly ?SendsReminderTelegram $telegram = null,
    ) {}

    /**
     * @param  array{telegram?: bool, inbox?: bool}  $delivery
     */
    public function generate(
        User $user,
        ExecutiveBriefType $type,
        CarbonImmutable $now,
        string $origin = 'scheduled',
        ?int $regeneratedFromId = null,
        array $delivery = [],
    ): ExecutiveBrief {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $locale = $this->locales->interfaceLocale($user);
        $local = $now->utc()->setTimezone($timezone);
        $date = $local->toDateString();
        $runKey = $origin === 'scheduled'
            ? AutomationRunKey::executiveBrief((int) $user->id, $type->value, $date)
            : AutomationRunKey::executiveBriefManual((int) $user->id, $type->value, $date, Str::lower(Str::random(8)));

        $brief = null;
        $this->executor->run(
            $user,
            AutomationType::ExecutiveBrief,
            0,
            $runKey,
            function ($run) use ($user, $type, $now, $timezone, $locale, $local, $date, $origin, $regeneratedFromId, $delivery, $runKey, &$brief): AutomationResult {
                $brief = ExecutiveBrief::query()->where('user_id', $user->id)->where('run_key', $runKey)->first();
                if ($brief instanceof ExecutiveBrief) {
                    return AutomationResult::of(
                        $brief->status === ExecutiveBriefStatus::Partial ? AutomationRunOutcome::Partial : AutomationRunOutcome::Success,
                        'already_recorded',
                        '',
                        0,
                        [],
                        $brief->delivery_status,
                    );
                }

                $started = hrtime(true);
                $previous = $origin === 'scheduled'
                    ? ExecutiveBrief::query()
                        ->where('user_id', $user->id)
                        ->where('brief_type', $type)
                        ->where('generated_for', '<', $local->toDateString())
                        ->orderByDesc('id')
                        ->first()
                    : null;

                $collected = $this->collector->collect($user, $type, $now, $timezone);
                $assembled = $this->assembler->assemble(
                    $collected['items'],
                    $previous,
                    (int) config('executive_brief.attention_limit', 7),
                );
                $composed = $this->composer->compose($user, $type, $assembled['sections'], $collected['snapshot'], $locale);

                $snapshot = $collected['snapshot'];
                $snapshot['items_rendered'] = $this->countItems($assembled['sections']);
                $snapshot['prompt_version'] = (string) config('executive_brief.prompt_version');
                $snapshot['ai_used'] = $composed['ai_used'];

                $errors = is_array($snapshot['errors'] ?? null) ? array_values(array_filter($snapshot['errors'])) : [];
                $blocked = is_array($snapshot['blocked'] ?? null) ? $snapshot['blocked'] : [];
                $failed = (int) ($snapshot['sources_failed'] ?? 0);
                $attempted = (int) ($snapshot['sources_attempted'] ?? 0);

                $status = ExecutiveBriefStatus::Ready;
                $outcome = AutomationRunOutcome::Success;
                if ($failed > 0 && $failed >= $attempted) {
                    $status = ExecutiveBriefStatus::Failed;
                    $outcome = AutomationRunOutcome::Failed;
                } elseif ($errors !== [] || $blocked !== [] || $failed > 0) {
                    $status = ExecutiveBriefStatus::Partial;
                    $outcome = AutomationRunOutcome::Partial;
                }

                $brief = ExecutiveBrief::query()->create([
                    'user_id' => $user->id,
                    'brief_type' => $type,
                    'origin' => $origin,
                    'period_start' => $local->startOfDay()->subDay()->utc(),
                    'period_end' => $local->endOfDay()->utc(),
                    'generated_for' => $date,
                    'timezone' => $timezone,
                    'locale' => $locale->value,
                    'status' => $status,
                    'priority_score' => $assembled['priority_score'],
                    'summary' => $composed['summary'],
                    'sections_json' => $assembled['sections'],
                    'source_snapshot_json' => $snapshot,
                    'automation_run_id' => $run->id,
                    'regenerated_from_id' => $regeneratedFromId,
                    'run_key' => $runKey,
                    'generated_at' => CarbonImmutable::now('UTC'),
                ]);

                $delivered = false;
                if ($status !== ExecutiveBriefStatus::Failed) {
                    $delivered = $this->deliver($user, $brief, $composed['summary'], $assembled['sections'], $snapshot, $locale, $delivery);
                }
                $brief->forceFill([
                    'delivered_at' => $delivered ? CarbonImmutable::now('UTC') : $brief->delivered_at,
                    'delivery_status' => $delivered ? 'success' : 'skipped',
                ])->save();

                Log::info('executive brief generated', [
                    'brief_id' => $brief->id,
                    'run_id' => $run->id,
                    'run_key' => $runKey,
                    'status' => $status->value,
                    'sources_attempted' => $attempted,
                    'sources_succeeded' => (int) ($snapshot['sources_succeeded'] ?? 0),
                    'attention_count' => $assembled['attention_count'],
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ]);

                return AutomationResult::of(
                    $outcome,
                    $status->value,
                    '',
                    (int) ((hrtime(true) - $started) / 1_000_000),
                    [
                        'sources_attempted' => $attempted,
                        'sources_succeeded' => (int) ($snapshot['sources_succeeded'] ?? 0),
                        'sources_failed' => $failed,
                        'items_collected' => (int) ($snapshot['items_collected'] ?? 0),
                        'items_rendered' => (int) $snapshot['items_rendered'],
                        'prompt_version' => (string) $snapshot['prompt_version'],
                        'ai_used' => (bool) $composed['ai_used'],
                    ],
                    $delivered ? 'success' : 'skipped',
                );
            },
            $now,
            $runKey,
            rethrowRetryable: false,
        );

        if ($brief instanceof ExecutiveBrief) {
            return $brief;
        }

        $existing = ExecutiveBrief::query()->where('user_id', $user->id)->where('run_key', $runKey)->first();
        if ($existing instanceof ExecutiveBrief) {
            return $existing;
        }

        throw new \RuntimeException('executive_brief_missing');
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $snapshot
     * @param  array{telegram?: bool, inbox?: bool}  $delivery
     */
    private function deliver(
        User $user,
        ExecutiveBrief $brief,
        string $summary,
        array $sections,
        array $snapshot,
        OwnerLocale $locale,
        array $delivery,
    ): bool {
        $wantTelegram = (bool) ($delivery['telegram'] ?? true);
        $wantInbox = (bool) ($delivery['inbox'] ?? true);
        $sentTelegram = false;

        if ($wantTelegram && $this->telegram !== null) {
            $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
            $chatId = ($identity !== null && filled($identity->external_chat_id))
                ? (string) $identity->external_chat_id
                : null;
            if ($chatId !== null) {
                $text = $this->telegramRenderer->render(
                    $summary,
                    $sections,
                    $snapshot,
                    $locale,
                    (int) config('executive_brief.telegram_max_chars', 3500),
                );
                try {
                    $this->telegram->send($chatId, $text, 'brief_'.$brief->id);
                    $sentTelegram = true;
                } catch (\Throwable) {
                }
            }
        }

        $sentInbox = false;
        if ($wantInbox && ! $sentTelegram) {
            $this->inbox->record(
                $user,
                JarvisNotificationType::ExecutiveBriefReady,
                $this->inboxTitle($locale),
                $summary,
                'executive_brief:'.$brief->id,
                'executive_brief',
                (int) $brief->id,
                '/lavr/briefs/'.$brief->id,
                [
                    'executive_brief_id' => (int) $brief->id,
                    'brief_type' => $brief->brief_type instanceof ExecutiveBriefType ? $brief->brief_type->value : (string) $brief->brief_type,
                ],
            );
            $sentInbox = true;
        }

        return $sentTelegram || $sentInbox;
    }

    private function inboxTitle(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Morning brief is ready',
            OwnerLocale::Ru => 'Утренний бриф готов',
            default => 'Ранковий бриф готовий',
        };
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    private function countItems(array $sections): int
    {
        $count = 0;
        foreach ($sections as $rows) {
            if (is_array($rows)) {
                $count += count($rows);
            }
        }

        return $count;
    }
}
