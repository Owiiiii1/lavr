<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;

final class ListRemindersTool implements JarvisTool
{
    public const NAME = 'list_reminders';

    public function __construct(
        private readonly ReminderService $reminders,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists the current user’s open LAVR reminders (scheduled and due). Use before update/snooze/done/cancel when the target reminder is unclear. Never guess among several matches.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'limit' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional maximum number of reminders. Core caps this.',
                    ],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::REMINDERS,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::REMINDERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $limit = isset($call->arguments['limit']) ? (int) $call->arguments['limit'] : 12;

        try {
            $items = $this->reminders->listUpcoming($context->user, $limit)
                ->map(fn ($reminder): array => [
                    'id' => (int) $reminder->id,
                    'text' => $reminder->text,
                    'status' => $reminder->status->value,
                    'run_at' => optional($reminder->run_at)?->toIso8601String(),
                    'timezone' => $reminder->timezone,
                    'recurrence' => $reminder->recurrence_rule,
                ])
                ->values()
                ->all();
        } catch (ReminderException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($items),
            'reminders' => $items,
        ]);
    }
}
