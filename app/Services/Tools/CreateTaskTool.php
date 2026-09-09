<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Models\Task;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tasks\TaskException;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;

final class CreateTaskTool implements JarvisTool
{
    public const NAME = 'create_task';

    public function __construct(
        private readonly TaskService $tasks,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a personal LAVR task (a commitment), not a reminder. Call only when the user explicitly asked to create/remember a task or stated a clear commitment. Do not create tasks from vague “надо бы”. Optional due datetime, priority, description, Owner project_id, optional Google calendar reference.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'title' => ['type' => 'STRING', 'description' => 'Short task title without the time phrase if a due date is separate.'],
                    'description' => ['type' => 'STRING', 'description' => 'Optional details.'],
                    'priority' => ['type' => 'STRING', 'description' => 'low, normal, high, or urgent. Default normal.'],
                    'due_at_local' => ['type' => 'STRING', 'description' => 'Optional ISO 8601 local due datetime.'],
                    'timezone' => ['type' => 'STRING', 'description' => 'IANA timezone. Defaults to the user timezone.'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owner-only owned project id. Omit for ordinary users.'],
                    'calendar_provider' => ['type' => 'STRING', 'description' => 'Optional calendar provider, e.g. google. Do not invent.'],
                    'calendar_id' => ['type' => 'STRING', 'description' => 'Optional calendar id when the user asked to link an event.'],
                    'calendar_event_id' => ['type' => 'STRING', 'description' => 'Optional event id when the user asked to link an event.'],
                ],
                'required' => ['title'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::TASKS,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::TASKS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $title = trim((string) ($call->arguments['title'] ?? ''));

        if ($title === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $timezone = trim((string) ($call->arguments['timezone'] ?? $context->user->timezone ?: 'UTC'));
            $dueLocal = isset($call->arguments['due_at_local']) ? trim((string) $call->arguments['due_at_local']) : '';
            $dueAt = $dueLocal !== '' ? $this->tasks->localWallTimeToUtc($dueLocal, $timezone) : null;
            $priority = isset($call->arguments['priority']) ? $this->tasks->normalizePriority($call->arguments['priority']) : null;
            $task = $this->tasks->create(
                user: $context->user,
                title: $title,
                description: isset($call->arguments['description']) ? (string) $call->arguments['description'] : null,
                priority: $priority,
                dueAt: $dueAt,
                timezone: $timezone,
                conversation: $context->conversation,
                sourceMessage: $context->inbound,
                projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                calendarProvider: isset($call->arguments['calendar_provider']) ? trim((string) $call->arguments['calendar_provider']) : null,
                calendarId: isset($call->arguments['calendar_id']) ? trim((string) $call->arguments['calendar_id']) : null,
                calendarEventId: isset($call->arguments['calendar_event_id']) ? trim((string) $call->arguments['calendar_event_id']) : null,
            );
        } catch (TaskException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), $this->successPayload($task));
    }

    /**
     * @return array<string, mixed>
     */
    public function successPayload(Task $task): array
    {
        return [
            'success' => true,
            'task_id' => (int) $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
            'due_at' => optional($task->due_at)?->toIso8601String(),
        ];
    }
}
