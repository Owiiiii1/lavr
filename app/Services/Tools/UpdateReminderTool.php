<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;

final class UpdateReminderTool implements JarvisTool
{
    public const NAME = 'update_reminder';

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
            description: 'Updates an owned LAVR reminder: text, time, timezone, or recurrence. If several reminders match, do not guess — call list_reminders and ask. Never pass user_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'reminder_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Id of the reminder to update. Required unless query uniquely identifies one reminder.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional text snippet to find the reminder when id is unknown.',
                    ],
                    'text' => [
                        'type' => 'STRING',
                        'description' => 'New reminder text.',
                    ],
                    'run_at_local' => [
                        'type' => 'STRING',
                        'description' => 'New local ISO 8601 datetime.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'IANA timezone, e.g. Europe/Rome.',
                    ],
                    'recurrence' => [
                        'type' => 'STRING',
                        'description' => 'daily, weekdays, weekly, monthly, or empty to clear.',
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

        $hasText = array_key_exists('text', $call->arguments);
        $hasTime = array_key_exists('run_at_local', $call->arguments);
        $hasTimezone = array_key_exists('timezone', $call->arguments);
        $hasRecurrence = array_key_exists('recurrence', $call->arguments) || array_key_exists('recurrence_rule', $call->arguments);

        if (! $hasText && ! $hasTime && ! $hasTimezone && ! $hasRecurrence) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $recurrence = false;

            if ($hasRecurrence) {
                $raw = $call->arguments['recurrence'] ?? $call->arguments['recurrence_rule'] ?? null;
                $recurrence = is_string($raw) ? $raw : null;
            }

            $reminder = $this->reminders->updateOwned(
                $context->user,
                (int) $picked['reminder']->id,
                $hasText ? trim((string) $call->arguments['text']) : null,
                $hasTime ? trim((string) $call->arguments['run_at_local']) : null,
                $hasTimezone ? trim((string) $call->arguments['timezone']) : null,
                $recurrence,
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
            'text' => $reminder->text,
            'recurrence' => $reminder->recurrence_rule,
            'status' => $reminder->status->value,
        ]);
    }
}
