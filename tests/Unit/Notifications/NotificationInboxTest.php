<?php

namespace Tests\Unit\Notifications;

use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\JarvisNotification;
use App\Models\User;
use App\Services\Notifications\NotificationInbox;
use App\Services\Notifications\NotificationUrlPolicy;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class NotificationInboxTest extends TestCase
{
    public function test_dedupe_blocks_a_second_identical_event(): void
    {
        $inbox = new NotificationInbox;
        $user = $this->user();
        $existing = $inbox->make(
            $user,
            JarvisNotificationType::TaskDue,
            'Задача на сегодня',
            'Срок сегодня: отчёт',
            'task_due:1:2026-09-06',
            CarbonImmutable::parse('2026-09-06 08:00:00', 'UTC'),
        );

        $this->assertFalse($inbox->shouldCreate($existing));
        $this->assertTrue($inbox->shouldCreate(null));
    }

    public function test_read_and_dismiss_and_unread_count(): void
    {
        $inbox = new NotificationInbox;
        $now = CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC');
        $one = $inbox->make($this->user(), JarvisNotificationType::TaskOverdue, 'a', 'b', 'k1', $now);
        $two = $inbox->make($this->user(), JarvisNotificationType::BriefReady, 'c', 'd', 'k2', $now);

        $this->assertSame(2, $inbox->unreadCount([$one, $two]));
        $inbox->markRead($one, $now);
        $this->assertFalse($one->isUnread());
        $inbox->dismiss($two, $now);
        $this->assertNotNull($two->dismissed_at);
        $this->assertSame(0, $inbox->unreadCount([$one, $two]));
    }

    public function test_safe_urls_are_workspace_only(): void
    {
        $policy = new NotificationUrlPolicy;
        $owner = $this->user(UserRole::Owner);
        $user = $this->user(UserRole::User, 2);

        $this->assertSame('/lavr/chats/9?task=1', $policy->workspacePath($owner, 'task=1', 9));
        $this->assertSame('/lavr?notifications=1', $policy->workspacePath($user, 'notifications=1'));
        $this->assertTrue($policy->isSafe('/lavr/chats/9'));
        $this->assertTrue($policy->isSafe('/lavr?task=1'));
        $this->assertTrue($policy->isSafe('/jarvis/chats/9'));
        $this->assertTrue($policy->isSafe('/chat?task=1'));
        $this->assertFalse($policy->isSafe('https://evil.example/'));
        $this->assertFalse($policy->isSafe('//evil.example'));
        $this->assertNull($policy->sanitize('javascript:alert(1)'));
        $this->assertNull($policy->sanitize('/dashboard'));
    }

    public function test_types_cover_the_b2_inbox(): void
    {
        $this->assertSame('reminder_due', JarvisNotificationType::ReminderDue->value);
        $this->assertSame('task_due', JarvisNotificationType::TaskDue->value);
        $this->assertSame('task_overdue', JarvisNotificationType::TaskOverdue->value);
        $this->assertSame('brief_ready', JarvisNotificationType::BriefReady->value);
        $this->assertSame('proactive_suggestion', JarvisNotificationType::ProactiveSuggestion->value);
        $this->assertSame('urgent', JarvisNotificationSeverity::Urgent->value);
    }

    public function test_ownership_is_user_id_scoped_on_the_row(): void
    {
        $row = new JarvisNotification;
        $row->forceFill(['user_id' => 3, 'type' => JarvisNotificationType::TaskDue]);
        $this->assertSame(3, (int) $row->user_id);
        $this->assertNotSame(1, (int) $row->user_id);
    }

    private function user(UserRole $role = UserRole::User, int $id = 1): User
    {
        $user = new User;
        $user->forceFill([
            'id' => $id,
            'role' => $role,
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);

        return $user;
    }
}
