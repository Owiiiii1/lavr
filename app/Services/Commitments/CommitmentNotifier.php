<?php

namespace App\Services\Commitments;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\JarvisNotificationType;
use App\Enums\OwnerLocale;
use App\Models\ChannelIdentity;
use App\Models\Commitment;
use App\Models\User;
use App\Services\Automation\AutomationEvent;
use App\Services\Automation\AutomationEventBus;
use App\Services\Automation\AutomationExecutor;
use App\Services\Automation\AutomationResult;
use App\Services\Automation\AutomationRunKey;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Illuminate\Support\Facades\Log;

final class CommitmentNotifier
{
    public function __construct(
        private readonly JarvisNotificationService $inbox = new JarvisNotificationService,
        private readonly AutomationExecutor $executor = new AutomationExecutor,
        private readonly AutomationEventBus $events = new AutomationEventBus,
        private readonly ?SendsReminderTelegram $telegram = null,
    ) {}

    public function notifyOpened(Commitment $commitment): void
    {
        $this->notify($commitment, JarvisNotificationType::CommitmentOpened, CommitmentEffectiveStatus::Open);
    }

    public function notifyLikelyDone(Commitment $commitment): void
    {
        $this->notify($commitment, JarvisNotificationType::CommitmentLikelyDone, CommitmentEffectiveStatus::LikelyDone);
    }

    public function notifyStatusTransition(Commitment $commitment, CommitmentEffectiveStatus $status): void
    {
        $type = match ($status) {
            CommitmentEffectiveStatus::DueSoon => JarvisNotificationType::CommitmentDueSoon,
            CommitmentEffectiveStatus::Overdue => JarvisNotificationType::CommitmentOverdue,
            CommitmentEffectiveStatus::LikelyDone => JarvisNotificationType::CommitmentLikelyDone,
            CommitmentEffectiveStatus::Open => JarvisNotificationType::CommitmentOpened,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->notify($commitment, $type, $status);
    }

    private function notify(Commitment $commitment, JarvisNotificationType $type, CommitmentEffectiveStatus $status): void
    {
        $user = $commitment->user ?? User::query()->find($commitment->user_id);

        if (! $user instanceof User || ! $user->isActive()) {
            return;
        }

        if ($commitment->last_notified_status === $status->value) {
            return;
        }

        $locale = app(OwnerLocaleResolver::class)->interfaceLocale($user);
        $title = $this->titleFor($type, $locale);

        $who = $commitment->person?->display_name ?? $commitment->person_name_raw ?? $this->someone($locale);
        $body = $who.': '.$commitment->title;
        if ($status === CommitmentEffectiveStatus::Overdue) {
            $body .= ' '.$this->followUpPrompt($locale, is_string($who) ? $who : null);
        }

        $runKey = AutomationRunKey::commitmentTransition((int) $commitment->id, $status->value);
        $this->executor->run(
            $user,
            AutomationType::Commitment,
            (int) $commitment->id,
            $runKey,
            function () use ($commitment, $user, $type, $title, $body, $status): AutomationResult {
                $row = $this->inbox->record(
                    $user,
                    $type,
                    $title,
                    $body,
                    'commitment:'.$commitment->id.':'.$status->value,
                    'commitment',
                    (int) $commitment->id,
                    '/lavr/commitments/'.$commitment->id,
                    [
                        'trigger' => $type->value,
                        'source_id' => (int) $commitment->id,
                    ],
                );

                if ($row !== null) {
                    $commitment->forceFill([
                        'last_notified_status' => $status->value,
                        'last_notified_at' => now(),
                    ])->save();
                    $this->maybeTelegram($user, $body);
                    if ($status === CommitmentEffectiveStatus::Overdue) {
                        $this->events->emit(new AutomationEvent('commitment.overdue', (int) $user->id, (int) $commitment->id));
                    }
                }

                Log::info('commitment notification', [
                    'commitment_id' => $commitment->id,
                    'person_id' => $commitment->person_id,
                    'status' => $status->value,
                    'source_type' => $commitment->source_type instanceof \BackedEnum ? $commitment->source_type->value : (string) $commitment->source_type,
                    'source_id' => $commitment->source_id,
                    'outcome' => $row === null ? 'deduped' : 'recorded',
                ]);

                return AutomationResult::of(
                    $row === null ? AutomationRunOutcome::NoChange : AutomationRunOutcome::Success,
                    $row === null ? 'deduped' : 'notified',
                    '',
                    0,
                    [],
                    $row === null ? 'deduped' : 'success',
                );
            },
            null,
            $runKey,
        );
    }

    private function titleFor(JarvisNotificationType $type, OwnerLocale $locale): string
    {
        return match ($type) {
            JarvisNotificationType::CommitmentDueSoon => match ($locale) {
                OwnerLocale::En => 'Commitment due soon',
                OwnerLocale::Ru => 'Скоро срок обязательства',
                default => 'Скоро строк зобов’язання',
            },
            JarvisNotificationType::CommitmentOverdue => match ($locale) {
                OwnerLocale::En => 'Commitment overdue',
                OwnerLocale::Ru => 'Обязательство просрочено',
                default => 'Зобов’язання прострочено',
            },
            JarvisNotificationType::CommitmentLikelyDone => match ($locale) {
                OwnerLocale::En => 'Commitment looks done',
                OwnerLocale::Ru => 'Обязательство похоже выполнено',
                default => 'Зобов’язання схоже виконано',
            },
            default => match ($locale) {
                OwnerLocale::En => 'Commitment opened',
                OwnerLocale::Ru => 'Обязательство открыто',
                default => 'Зобов’язання відкрито',
            },
        };
    }

    private function followUpPrompt(OwnerLocale $locale, ?string $who): string
    {
        $name = $who !== null && $who !== '' ? $who : $this->someone($locale);

        return match ($locale) {
            OwnerLocale::En => $name.' missed the deadline. Remind them? LAVR will not write to them unless you allow it.',
            OwnerLocale::Ru => $name.' просрочил срок. Напомнить? LAVR не напишет человеку без вашего разрешения.',
            default => $name.' прострочив строк. Нагадати? LAVR не напише людині без вашого дозволу.',
        };
    }

    private function maybeTelegram(User $user, string $text): void
    {
        if ($this->telegram === null) {
            return;
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
        $chatId = ($identity !== null && filled($identity->external_chat_id))
            ? (string) $identity->external_chat_id
            : null;

        if ($chatId === null) {
            return;
        }

        try {
            $this->telegram->send($chatId, $text, 'commitments');
        } catch (\Throwable) {
        }
    }

    private function someone(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Someone',
            OwnerLocale::Ru => 'Кто-то',
            default => 'Хтось',
        };
    }
}
