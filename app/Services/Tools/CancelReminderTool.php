<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderService;
use App\Services\Users\UserCapability;

final class CancelReminderTool implements JarvisTool
{
    public const NAME = 'cancel_reminder';

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
            description: 'Cancels an owned LAVR reminder. Distinct from done. Recurring series stop. If several reminders match, do not guess.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'reminder_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Id of the reminder to cancel.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional text snippet when id is unknown.',
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

        try {
            $reminder = $this->reminders->cancelOwned($context->user, (int) $picked['reminder']->id);
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
        ]);
    }
}
