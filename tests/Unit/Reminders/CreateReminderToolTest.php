<?php

namespace Tests\Unit\Reminders;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Reminders\ReminderService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ToolExecutionContext;
use Tests\TestCase;

class CreateReminderToolTest extends TestCase
{
    public function test_definition_does_not_require_telegram(): void
    {
        $definition = (new CreateReminderTool(new ReminderService))->definition();

        $this->assertStringContainsString('LAVR', $definition->description);
        $this->assertStringContainsString('без Telegram', $definition->description);
        $this->assertStringNotContainsString('только в Telegram', $definition->description);
    }

    public function test_success_payload_for_missing_telegram_does_not_promise_delivery(): void
    {
        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => 15,
            'text' => 'проверить чайник',
        ]);

        $payload = (new CreateReminderTool(new ReminderService))->successPayload(
            $reminder,
            '2026-09-06T12:05:00+02:00',
            'Europe/Rome',
            false,
        );

        $this->assertTrue($payload['success']);
        $this->assertSame(15, $payload['reminder_id']);
        $this->assertFalse($payload['telegram_connected']);
        $this->assertSame('none', $payload['delivery']);
        $this->assertSame('2026-09-06T12:05:00+02:00', $payload['run_at_local']);
        $this->assertSame('Europe/Rome', $payload['timezone']);
    }

    public function test_success_payload_for_linked_telegram_marks_telegram_delivery(): void
    {
        $reminder = new Reminder;
        $reminder->forceFill([
            'id' => 16,
            'text' => 'проверить чайник',
        ]);

        $payload = (new CreateReminderTool(new ReminderService))->successPayload(
            $reminder,
            '2026-09-06T12:05:00+02:00',
            'Europe/Rome',
            true,
        );

        $this->assertTrue($payload['telegram_connected']);
        $this->assertSame('telegram', $payload['delivery']);
    }

    public function test_execute_rejects_invalid_recurrence_without_persisting(): void
    {
        $result = (new CreateReminderTool(new ReminderService))->execute(
            new ToolCall('c1', CreateReminderTool::NAME, [
                'text' => 'every morning',
                'run_at_local' => '2026-09-07T10:00:00+02:00',
                'recurrence' => 'FREQ=DAILY',
            ]),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_recurrence', $result->payload['error']);
    }

    public function test_execute_rejects_empty_text(): void
    {
        $result = (new CreateReminderTool(new ReminderService))->execute(
            new ToolCall('c1', CreateReminderTool::NAME, [
                'text' => '  ',
                'run_at_local' => '2026-09-07T10:00:00+02:00',
            ]),
            $this->context(),
        );

        $this->assertFalse($result->success);
        $this->assertSame('invalid_arguments', $result->payload['error']);
    }

    private function context(): ToolExecutionContext
    {
        $user = new User;
        $user->forceFill([
            'id' => 3,
            'role' => UserRole::User,
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);

        $conversation = new Conversation;
        $conversation->forceFill([
            'id' => 8,
            'user_id' => 3,
        ]);

        return new ToolExecutionContext($user, $conversation);
    }
}
