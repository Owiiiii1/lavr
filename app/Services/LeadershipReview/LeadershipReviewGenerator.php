<?php

namespace App\Services\LeadershipReview;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\JarvisNotificationType;
use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use App\Enums\OwnerLocale;
use App\Models\ChannelIdentity;
use App\Models\LeadershipReview;
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

final class LeadershipReviewGenerator
{
    public function __construct(
        private readonly LeadershipReviewCollector $collector,
        private readonly LeadershipReviewMetrics $metrics,
        private readonly LeadershipReviewFindings $findings,
        private readonly LeadershipReviewComposer $composer,
        private readonly LeadershipReviewTelegramRenderer $telegramRenderer,
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
        LeadershipReviewType $type,
        LeadershipReviewPeriod $period,
        string $origin = 'manual',
        ?int $projectId = null,
        ?int $personId = null,
        ?int $meetingId = null,
        array $delivery = [],
    ): LeadershipReview {
        $locale = $this->locales->interfaceLocale($user);
        $runKey = $this->runKey($user, $type, $period, $origin, $projectId, $personId, $meetingId);
        $review = null;

        $this->executor->run(
            $user,
            AutomationType::LeadershipReview,
            0,
            $runKey,
            function ($run) use ($user, $type, $period, $origin, $projectId, $personId, $meetingId, $delivery, $runKey, $locale, &$review): AutomationResult {
                $existing = LeadershipReview::query()->where('user_id', $user->id)->where('run_key', $runKey)->first();
                if ($existing instanceof LeadershipReview) {
                    $review = $existing;
                    $status = $existing->status instanceof LeadershipReviewStatus ? $existing->status : LeadershipReviewStatus::Ready;

                    return AutomationResult::of(
                        $this->outcomeFor($status),
                        'already_recorded',
                        '',
                        0,
                        [],
                        $existing->delivery_status,
                    );
                }

                $started = hrtime(true);
                $collected = $this->collector->collect($user, $period, $type, $projectId, $personId, $meetingId);
                $previousCollected = $this->collector->collect($user, $period->previous(), $type, $projectId, $personId, $meetingId);
                $currentMetrics = $this->metrics->compute($collected);
                $previousMetrics = $this->metrics->compute($previousCollected);
                $pack = $this->findings->build($collected, $currentMetrics, $previousMetrics, $locale);
                $composed = $this->composer->compose(
                    $user,
                    $pack,
                    $currentMetrics,
                    $collected['snapshot'],
                    $locale,
                    is_array($collected['known_names'] ?? null) ? $collected['known_names'] : [],
                );

                $snapshot = is_array($collected['snapshot'] ?? null) ? $collected['snapshot'] : [];
                unset($snapshot['bodies'], $snapshot['emails'], $snapshot['transcripts']);
                $snapshot['ai_used'] = $composed['ai_used'];
                $snapshot['prompt_version'] = (string) config('leadership_review.prompt_version');
                $snapshot['previous_period_start'] = $period->previous()->start->toIso8601String();
                $snapshot['previous_period_end'] = $period->previous()->end->toIso8601String();

                $status = $this->statusFrom($snapshot, $currentMetrics);
                $review = LeadershipReview::query()->create([
                    'user_id' => $user->id,
                    'review_type' => $type,
                    'period_start' => $period->start,
                    'period_end' => $period->end,
                    'project_id' => $projectId,
                    'person_id' => $personId,
                    'meeting_id' => $meetingId,
                    'status' => $status,
                    'summary' => $composed['summary'],
                    'metrics_json' => $currentMetrics,
                    'findings_json' => $composed['pack'],
                    'source_snapshot_json' => $snapshot,
                    'generated_at' => CarbonImmutable::now('UTC'),
                    'generated_by' => $composed['generated_by'],
                    'origin' => $origin,
                    'run_key' => $runKey,
                    'automation_run_id' => $run->id,
                    'timezone' => $period->timezone,
                    'locale' => $locale->value,
                ]);

                $delivered = false;
                if (! in_array($status, [LeadershipReviewStatus::Failed, LeadershipReviewStatus::Skipped, LeadershipReviewStatus::InsufficientData], true)) {
                    $delivered = $this->deliver($user, $review, $composed['summary'], $composed['pack'], $locale, $delivery);
                }
                $review->forceFill([
                    'delivered_at' => $delivered ? CarbonImmutable::now('UTC') : $review->delivered_at,
                    'delivery_status' => $delivered ? 'success' : 'skipped',
                ])->save();

                Log::info('leadership review generated', [
                    'review_id' => $review->id,
                    'period' => $period->start->toDateString().'/'.$period->end->toDateString(),
                    'status' => $status->value,
                    'counts' => [
                        'commitments' => (int) ($currentMetrics['commitments_total'] ?? 0),
                        'meetings' => (int) ($currentMetrics['meetings_total'] ?? 0),
                        'findings' => count($composed['pack']['findings'] ?? []),
                    ],
                    'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                ]);

                return AutomationResult::of(
                    $this->outcomeFor($status),
                    $status->value,
                    '',
                    (int) ((hrtime(true) - $started) / 1_000_000),
                    [
                        'commitments' => (int) ($currentMetrics['commitments_total'] ?? 0),
                        'meetings' => (int) ($currentMetrics['meetings_total'] ?? 0),
                        'ai_used' => (bool) $composed['ai_used'],
                    ],
                    $delivered ? 'success' : 'skipped',
                );
            },
            CarbonImmutable::now('UTC'),
            $runKey,
            rethrowRetryable: false,
        );

        if ($review instanceof LeadershipReview) {
            return $review;
        }

        $existing = LeadershipReview::query()->where('user_id', $user->id)->where('run_key', $runKey)->first();
        if ($existing instanceof LeadershipReview) {
            return $existing;
        }

        throw new \RuntimeException('leadership_review_missing');
    }

    private function runKey(
        User $user,
        LeadershipReviewType $type,
        LeadershipReviewPeriod $period,
        string $origin,
        ?int $projectId,
        ?int $personId,
        ?int $meetingId,
    ): string {
        if ($origin === 'scheduled' && $type === LeadershipReviewType::Owner) {
            return AutomationRunKey::leadershipReviewWeekly((int) $user->id, $period->start->utc()->toDateString());
        }

        return AutomationRunKey::leadershipReviewManual(
            (int) $user->id,
            $type->value,
            $period->start->utc()->toDateString(),
            implode('-', array_filter([
                $projectId,
                $personId,
                $meetingId,
                Str::lower(Str::random(8)),
            ])),
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $metrics
     */
    private function statusFrom(array $snapshot, array $metrics): LeadershipReviewStatus
    {
        $failed = (int) ($snapshot['sources_failed'] ?? 0);
        $attempted = (int) ($snapshot['sources_attempted'] ?? 0);
        $commitments = (int) ($metrics['commitments_total'] ?? 0);
        $meetings = (int) ($metrics['meetings_total'] ?? 0);

        if ($commitments === 0 && $meetings === 0) {
            return LeadershipReviewStatus::InsufficientData;
        }
        if ($failed > 0 && $failed >= $attempted) {
            return LeadershipReviewStatus::Failed;
        }
        if ($failed > 0) {
            return LeadershipReviewStatus::Partial;
        }

        return LeadershipReviewStatus::Ready;
    }

    private function outcomeFor(LeadershipReviewStatus $status): AutomationRunOutcome
    {
        return match ($status) {
            LeadershipReviewStatus::Ready => AutomationRunOutcome::Success,
            LeadershipReviewStatus::Partial => AutomationRunOutcome::Partial,
            LeadershipReviewStatus::Failed => AutomationRunOutcome::Failed,
            LeadershipReviewStatus::Skipped, LeadershipReviewStatus::InsufficientData => AutomationRunOutcome::Skipped,
            default => AutomationRunOutcome::Partial,
        };
    }

    /**
     * @param  array<string, mixed>  $pack
     * @param  array{telegram?: bool, inbox?: bool}  $delivery
     */
    private function deliver(
        User $user,
        LeadershipReview $review,
        string $summary,
        array $pack,
        OwnerLocale $locale,
        array $delivery,
    ): bool {
        $wantTelegram = (bool) ($delivery['telegram'] ?? true);
        $wantInbox = (bool) ($delivery['inbox'] ?? true);
        $sentTelegram = false;
        $href = '/lavr/leadership/'.$review->id;

        if ($wantTelegram && $this->telegram !== null) {
            $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
            $chatId = ($identity !== null && filled($identity->external_chat_id))
                ? (string) $identity->external_chat_id
                : null;
            if ($chatId !== null) {
                $text = $this->telegramRenderer->render(
                    $summary,
                    $pack,
                    $href,
                    $locale,
                    (int) config('leadership_review.telegram_max_chars', 1200),
                );
                try {
                    $this->telegram->send($chatId, $text, 'leadership_'.$review->id);
                    $sentTelegram = true;
                } catch (\Throwable) {
                }
            }
        }

        if ($wantInbox && ! $sentTelegram) {
            $this->inbox->record(
                $user,
                JarvisNotificationType::LeadershipReviewReady,
                $this->inboxTitle($locale),
                $summary,
                'leadership_review:'.$review->id,
                'leadership_review',
                (int) $review->id,
                $href,
                ['leadership_review_id' => (int) $review->id],
            );

            return true;
        }

        return $sentTelegram;
    }

    private function inboxTitle(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Leadership Review is ready',
            OwnerLocale::Ru => 'Leadership Review готов',
            default => 'Leadership Review готовий',
        };
    }
}
