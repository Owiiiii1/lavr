<?php

namespace App\Services\Commitments;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\JarvisNotificationType;
use App\Enums\OwnerLocale;
use App\Models\Commitment;
use App\Models\User;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use Illuminate\Support\Facades\Log;

final class CommitmentNotifier
{
    public function __construct(
        private readonly JarvisNotificationService $inbox = new JarvisNotificationService,
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
        $row = $this->inbox->record(
            $user,
            $type,
            $title,
            $who.': '.$commitment->title,
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
        }

        Log::info('commitment notification', [
            'commitment_id' => $commitment->id,
            'person_id' => $commitment->person_id,
            'status' => $status->value,
            'source_type' => $commitment->source_type instanceof \BackedEnum ? $commitment->source_type->value : (string) $commitment->source_type,
            'source_id' => $commitment->source_id,
            'outcome' => $row === null ? 'deduped' : 'recorded',
        ]);
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

    private function someone(OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => 'Someone',
            OwnerLocale::Ru => 'Кто-то',
            default => 'Хтось',
        };
    }
}
