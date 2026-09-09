<?php

namespace App\Services\Reminders;

use App\Enums\JarvisNotificationType;
use App\Enums\ReminderChannel;
use App\Enums\ReminderDeliveryStatus;
use App\Enums\ReminderStatus;
use App\Models\ChannelIdentity;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\ReminderDelivery;
use App\Models\ReminderOccurrence;
use App\Models\User;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Reminders\Contracts\SendsWebPush;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReminderDeliveryService
{
    public const MAX_ATTEMPTS = ReminderDeliveryState::MAX_ATTEMPTS;

    public const NO_CHANNEL_RECHECK_MINUTES = ReminderDeliveryState::NO_CHANNEL_RECHECK_MINUTES;

    public function __construct(
        private readonly SendsReminderTelegram $telegram,
        private readonly SendsWebPush $webPush,
        private readonly PushPayloadBuilder $payloads,
        private readonly PushSubscriptionService $subscriptions,
        private readonly ReminderRecurrenceCalculator $recurrence,
        private readonly ?JarvisNotificationService $inbox = null,
    ) {}

    public function deliver(Reminder $reminder): void
    {
        $reminder->loadMissing('user');
        $user = $reminder->user;

        if ($user === null || ! $user->isActive()) {
            $this->cancel($reminder, 'user_disabled');

            return;
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
        $chatId = ($identity !== null && filled($identity->external_chat_id))
            ? (string) $identity->external_chat_id
            : null;
        $pushSubscriptions = $this->subscriptions->activeFor($user)->all();

        $attempts = $this->deliverToChannels($reminder, $chatId, $pushSubscriptions, CarbonImmutable::now('UTC'));
        $this->persist($reminder, $attempts, CarbonImmutable::now('UTC'));
    }

    /**
     * @param  list<PushSubscription>  $pushSubscriptions
     * @return list<ChannelAttempt>
     */
    public function deliverToChannels(
        Reminder $reminder,
        ?string $telegramChatId,
        array $pushSubscriptions,
        CarbonImmutable $now,
    ): array {
        $attempts = [
            $this->attemptTelegram($reminder, $telegramChatId),
            $this->attemptWebPush($reminder, $pushSubscriptions, $now),
        ];

        ReminderDeliveryState::applyAttempts($reminder, $attempts, $now, $this->recurrence);

        return $attempts;
    }

    private function attemptTelegram(Reminder $reminder, ?string $chatId): ChannelAttempt
    {
        $previous = (int) ($reminder->metadata['telegram_attempts'] ?? 0);

        if ($chatId === null || $chatId === '') {
            return new ChannelAttempt(
                ReminderChannel::Telegram,
                ReminderDeliveryStatus::Skipped,
                $previous,
                false,
            );
        }

        try {
            $this->telegram->send($chatId, '⏰ Напоминание: '.$reminder->text, 'today');
        } catch (Throwable $exception) {
            Log::warning('reminder telegram delivery failed', [
                'reminder_id' => $reminder->id,
                'user_id' => $reminder->user_id,
                'error_class' => $exception::class,
            ]);

            $attempts = $previous + 1;

            return new ChannelAttempt(
                ReminderChannel::Telegram,
                ReminderDeliveryStatus::Failed,
                $attempts,
                $attempts < self::MAX_ATTEMPTS,
                'telegram_delivery_failed',
            );
        }

        return new ChannelAttempt(
            ReminderChannel::Telegram,
            ReminderDeliveryStatus::Sent,
            $previous + 1,
            false,
        );
    }

    /**
     * @param  list<PushSubscription>  $subscriptions
     */
    private function attemptWebPush(Reminder $reminder, array $subscriptions, CarbonImmutable $now): ChannelAttempt
    {
        $previous = (int) ($reminder->metadata['push_attempts'] ?? 0);

        if ($subscriptions === [] || ! VapidConfig::isConfigured()) {
            return new ChannelAttempt(
                ReminderChannel::WebPush,
                ReminderDeliveryStatus::Skipped,
                $previous,
                false,
            );
        }

        $user = $reminder->user;

        if ($user === null) {
            return new ChannelAttempt(
                ReminderChannel::WebPush,
                ReminderDeliveryStatus::Skipped,
                $previous,
                false,
            );
        }

        $payload = $this->payloads->payload($reminder, $user, $now->toIso8601String());
        $anySent = false;
        $anyTransient = false;
        $activeRemaining = false;

        foreach ($subscriptions as $subscription) {
            $result = $this->webPush->send($subscription, $payload);

            if ($result->succeeded()) {
                $anySent = true;
                $activeRemaining = true;
                $subscription->forceFill(['last_used_at' => $now]);

                continue;
            }

            if ($result->gone() || $result->outcome->value === 'permanent') {
                $this->subscriptions->revoke($subscription);

                continue;
            }

            if ($result->retryable()) {
                $anyTransient = true;
                $activeRemaining = true;
            }
        }

        if ($anySent) {
            return new ChannelAttempt(
                ReminderChannel::WebPush,
                ReminderDeliveryStatus::Sent,
                $previous + 1,
                false,
            );
        }

        if ($anyTransient && $activeRemaining) {
            $attempts = $previous + 1;

            return new ChannelAttempt(
                ReminderChannel::WebPush,
                ReminderDeliveryStatus::Failed,
                $attempts,
                $attempts < self::MAX_ATTEMPTS,
                'web_push_delivery_failed',
            );
        }

        if (! $activeRemaining) {
            return new ChannelAttempt(
                ReminderChannel::WebPush,
                ReminderDeliveryStatus::Skipped,
                $previous,
                false,
                'subscription_expired',
            );
        }

        return new ChannelAttempt(
            ReminderChannel::WebPush,
            ReminderDeliveryStatus::Failed,
            $previous + 1,
            false,
            'web_push_delivery_failed',
        );
    }

    /**
     * @param  list<ChannelAttempt>  $attempts
     */
    public function persist(Reminder $reminder, array $attempts, CarbonImmutable $now): void
    {
        foreach ($attempts as $attempt) {
            $this->persistChannel($reminder, $attempt, $now);
        }

        $pending = $reminder->metadata['pending_occurrence'] ?? null;

        if (is_array($pending) && $reminder->exists && isset($pending['run_at'], $pending['status'])) {
            ReminderOccurrence::query()->create([
                'reminder_id' => $reminder->id,
                'run_at' => CarbonImmutable::parse($pending['run_at'])->utc(),
                'status' => $pending['status'],
                'delivered_at' => ($pending['status'] ?? null) === ReminderStatus::Delivered->value
                    ? $now
                    : null,
                'completed_at' => ($pending['status'] ?? null) === ReminderStatus::Completed->value
                    ? $now
                    : null,
                'delivery_snapshot' => $pending,
            ]);

            $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
            unset($metadata['pending_occurrence']);
            $reminder->metadata = $metadata;
        }

        if ($reminder->exists) {
            $reminder->save();
        }

        $this->recordInbox($reminder, $now);
    }

    public function persistChannel(Reminder $reminder, ChannelAttempt $attempt, CarbonImmutable $now): void
    {
        if (! $reminder->exists) {
            return;
        }

        ReminderDelivery::query()->updateOrCreate(
            [
                'reminder_id' => $reminder->id,
                'channel' => $attempt->channel->value,
            ],
            [
                'status' => $attempt->status->value,
                'attempts' => $attempt->attempts,
                'delivered_at' => $attempt->succeeded() ? $now : null,
                'last_error' => $attempt->error,
                'next_retry_at' => $attempt->retryable
                    ? $now->utc()->addMinutes(max(1, $attempt->attempts))
                    : null,
            ],
        );
    }

    private function cancel(Reminder $reminder, string $reason): void
    {
        $metadata = $reminder->metadata ?? [];
        $metadata['reason'] = $reason;

        $reminder->forceFill([
            'status' => ReminderStatus::Cancelled,
            'cancelled_at' => now(),
            'last_error' => $reason,
            'metadata' => $metadata,
        ])->save();

        Log::info('reminder cancelled', [
            'reminder_id' => $reminder->id,
            'user_id' => $reminder->user_id,
            'status' => ReminderStatus::Cancelled->value,
            'error_class' => $reason,
        ]);
    }

    private function recordInbox(Reminder $reminder, CarbonImmutable $now): void
    {
        if ($this->inbox === null) {
            return;
        }

        $user = $reminder->user;

        if (! $user instanceof User || ! $user->isActive()) {
            return;
        }

        $occurrence = $reminder->metadata['last_occurrence'] ?? null;
        $delivered = $reminder->status === ReminderStatus::Delivered
            || (is_array($occurrence) && ($occurrence['status'] ?? null) === ReminderStatus::Delivered->value);

        if (! $delivered) {
            return;
        }

        $stamp = is_array($occurrence) && isset($occurrence['run_at'])
            ? (string) $occurrence['run_at']
            : (optional($reminder->run_at)?->utc()->toIso8601String() ?? $now->utc()->toIso8601String());

        try {
            $this->inbox->record(
                $user,
                JarvisNotificationType::ReminderDue,
                'Напоминание',
                (string) $reminder->text,
                'reminder_due:'.$reminder->id.':'.$stamp,
                'reminder',
                (int) $reminder->id,
                (new NotificationUrlPolicy)->workspacePath(
                    $user,
                    'reminder='.$reminder->id,
                    $reminder->source_conversation_id ? (int) $reminder->source_conversation_id : null,
                ),
                [
                    'trigger' => 'reminder_due',
                    'source_id' => (int) $reminder->id,
                ],
                false,
                false,
            );
        } catch (Throwable) {
        }
    }
}
