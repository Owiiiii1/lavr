<?php

namespace Tests\Unit\Reminders;

use App\Enums\ReminderStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\WebPushSendOutcome;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Reminders\Contracts\SendsWebPush;
use App\Services\Reminders\PushPayloadBuilder;
use App\Services\Reminders\PushSubscriptionService;
use App\Services\Reminders\ReminderDeliveryService;
use App\Services\Reminders\ReminderRecurrenceCalculator;
use App\Services\Reminders\WebPushSendResult;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class ReminderDeliveryServiceTest extends TestCase
{
    public function test_telegram_failure_does_not_void_push_success(): void
    {
        config([
            'reminders.vapid.public_key' => 'public',
            'reminders.vapid.private_key' => 'private',
        ]);

        $telegram = new class implements SendsReminderTelegram
        {
            public function send(string $chatId, string $text, ?string $webAppStartParam = null): void
            {
                throw new RuntimeException('telegram down');
            }
        };

        $push = new class implements SendsWebPush
        {
            public function send(PushSubscription $subscription, array $payload): WebPushSendResult
            {
                return new WebPushSendResult(WebPushSendOutcome::Sent);
            }
        };

        $reminder = $this->reminder();
        $service = $this->service($telegram, $push);
        $service->deliverToChannels(
            $reminder,
            '1001',
            [$this->subscription()],
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
        );

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertSame('partial', $reminder->metadata['delivery_state']);
    }

    public function test_push_gone_deactivates_subscription_and_can_still_deliver_telegram(): void
    {
        config([
            'reminders.vapid.public_key' => 'public',
            'reminders.vapid.private_key' => 'private',
        ]);

        $telegram = new class implements SendsReminderTelegram
        {
            public function send(string $chatId, string $text, ?string $webAppStartParam = null): void {}
        };

        $push = new class implements SendsWebPush
        {
            public function send(PushSubscription $subscription, array $payload): WebPushSendResult
            {
                return new WebPushSendResult(WebPushSendOutcome::Gone, 'subscription_expired', 410);
            }
        };

        $subscription = $this->subscription();
        $reminder = $this->reminder();
        $this->service($telegram, $push)->deliverToChannels(
            $reminder,
            '1001',
            [$subscription],
            CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
        );

        $this->assertSame(ReminderStatus::Delivered, $reminder->status);
        $this->assertFalse($subscription->is_active);
        $this->assertSame('telegram', $reminder->metadata['delivery_channel']);
    }

    private function service(SendsReminderTelegram $telegram, SendsWebPush $push): ReminderDeliveryService
    {
        return new ReminderDeliveryService(
            $telegram,
            $push,
            new PushPayloadBuilder,
            new PushSubscriptionService,
            new ReminderRecurrenceCalculator,
        );
    }

    private function reminder(): Reminder
    {
        $user = new User;
        $user->forceFill([
            'id' => 4,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);

        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => 10,
            'user_id' => 4,
            'text' => 'чайник',
            'run_at' => CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'),
            'timezone' => 'Europe/Rome',
            'status' => ReminderStatus::Processing,
            'metadata' => ['attempts' => 0],
        ]);
        $reminder->setRelation('user', $user);

        return $reminder;
    }

    private function subscription(): PushSubscription
    {
        $subscription = new PushSubscription;
        $subscription->forceFill([
            'id' => 1,
            'user_id' => 4,
            'endpoint' => 'https://push.example/device',
            'p256dh' => 'p',
            'auth' => 'a',
            'is_active' => true,
        ]);

        return $subscription;
    }
}
