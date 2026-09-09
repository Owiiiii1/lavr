<?php

namespace App\Services\Tools\Meetings;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Meetings\Exceptions\MeetingException;
use App\Services\Meetings\MeetingService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class FindMeetingTool implements JarvisTool
{
    public const NAME = 'find_meeting';

    public function __construct(
        private readonly MeetingService $meetings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Find meetings by title, project name, participant, or summary. Use before get_meeting when the user names a show or person.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING', 'description' => 'Title, project, participant, or summary text.'],
                    'project_id' => ['type' => 'INTEGER'],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::MEETINGS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::MEETINGS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $meetings = $this->meetings->list(
                $context->user,
                $query,
                isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
            );
        } catch (MeetingException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'meetings' => $meetings->take(10)->map(fn ($meeting): array => $this->meetings->serializeSummary($meeting))->values()->all(),
        ]);
    }
}
