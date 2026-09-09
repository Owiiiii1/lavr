<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;

final class SnoozeReminderTool implements JarvisTool
{
    public const NAME = 'snooze_reminder';

    public function __construct(
        private readonly ReminderService $reminders,
        private readonly ReminderToolResolver $resolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Snoozes an owned LAVR reminder. Presets: 10m, 1h, tomorrow, or custom with run_at_local. If several reminders match, do not guess.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'reminder_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Id of the reminder to snooze.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional text snippet when id is unknown.',
                    ],
                    'preset' => [
                        'type' => 'STRING',
                        'description' => '10m, 1h, tomorrow, or custom.',
                    ],
                    'run_at_local' => [
                        'type' => 'STRING',
                        'description' => 'Required when preset is custom.',
                    ],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::REMINDERS,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::REMINDERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $picked = $this->resolver->resolve($call, $context, $this->name());

        if ($picked instanceof ToolResult) {
            return $picked;
        }

        $preset = strtolower(trim((string) ($call->arguments['preset'] ?? '1h')));
        $custom = isset($call->arguments['run_at_local']) ? trim((string) $call->arguments['run_at_local']) : null;

        try {
            $reminder = $this->reminders->snoozeOwned(
                $context->user,
                (int) $picked['reminder']->id,
                $preset,
                $custom,
            );
        } catch (ReminderException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'reminder_id' => $reminder->id,
            'status' => $reminder->status->value,
            'run_at' => optional($reminder->run_at)?->toIso8601String(),
        ]);
    }
}
