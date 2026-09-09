<?php

namespace Tests\Unit\Reminders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\PushSubscription;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\PushPayloadBuilder;
use App\Services\Reminders\PushSubscriptionService;
use App\Services\Reminders\ReminderException;
use Tests\TestCase;

class PushPayloadAndSubscriptionTest extends TestCase
{
    public function test_payload_is_bounded_and_allowlisted(): void
    {
        $user = $this->user(UserRole::User);
        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => 44,
            'text' => str_repeat('напомнить про врача и ещё детали ', 20),
            'source_conversation_id' => 9,
        ]);

        $builder = new PushPayloadBuilder;
        $payload = $builder->payload($reminder, $user, '2026-09-06T12:00:00+00:00');

        $this->assertTrue($builder->isBounded($payload));
        $this->assertSame(44, $payload['reminder_id']);
        $this->assertSame('LAVR', $payload['title']);
        $this->assertSame('/lavr/chats/9?reminder=44', $payload['url']);
        $this->assertArrayNotHasKey('prompt', $payload);
        $this->assertArrayNotHasKey('token', $payload);
        $this->assertFalse($builder->isAllowlisted('https://evil.example/phish'));
        $this->assertFalse($builder->isAllowlisted('/settings'));
    }

    public function test_owner_payload_uses_canonical_workspace_route(): void
    {
        $owner = $this->user(UserRole::Owner);
        $reminder = new Reminder;
        $reminder->forceFill(['id' => 3, 'text' => 'чай']);

        $payload = (new PushPayloadBuilder)->payload($reminder, $owner, '2026-09-06T12:00:00+00:00');

        $this->assertSame('/lavr?reminder=3', $payload['url']);
    }

    public function test_foreign_subscription_is_inaccessible(): void
    {
        $user = $this->user(UserRole::User, UserStatus::Active, 4);
        $foreign = new PushSubscription;
        $foreign->forceFill(['id' => 8, 'user_id' => 99, 'endpoint' => 'https://push.example/a']);

        try {
            (new PushSubscriptionService)->assertOwned($user, $foreign);
            $this->fail('Expected ReminderException.');
        } catch (ReminderException $exception) {
            $this->assertSame('not_found', $exception->error);
        }
    }

    public function test_expired_subscription_is_deactivated_without_infinite_retry(): void
    {
        $subscription = new PushSubscription;
        $subscription->forceFill([
            'id' => 2,
            'user_id' => 4,
            'endpoint' => 'https://push.example/gone',
            'is_active' => true,
        ]);

        (new PushSubscriptionService)->revoke($subscription);

        $this->assertFalse($subscription->is_active);
        $this->assertNotNull($subscription->revoked_at);
    }

    private function user(UserRole $role, UserStatus $status = UserStatus::Active, int $id = 1): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'name' => 'Test User',
            'email' => 'user'.$id.'@example.test',
            'role' => $role,
            'status' => $status,
            'timezone' => 'Europe/Rome',
        ]);

        return $user;
    }
}
