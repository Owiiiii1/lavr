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

final class ListMeetingsTool implements JarvisTool
{
    public const NAME = 'list_meetings';

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
            description: 'List Owner meetings with title, date, project, analysis status, and summary preview. Filter by project, date, or status.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
                    'project_id' => ['type' => 'INTEGER'],
                    'from' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    'to' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                    'analysis_status' => ['type' => 'STRING'],
                ],
                'required' => [],
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
        try {
            $meetings = $this->meetings->list(
                $context->user,
                isset($call->arguments['query']) ? (string) $call->arguments['query'] : null,
                isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                isset($call->arguments['from']) ? (string) $call->arguments['from'] : null,
                isset($call->arguments['to']) ? (string) $call->arguments['to'] : null,
                isset($call->arguments['analysis_status']) ? (string) $call->arguments['analysis_status'] : null,
            );
        } catch (MeetingException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'meetings' => $meetings->map(fn ($meeting): array => $this->meetings->serializeSummary($meeting))->values()->all(),
        ]);
    }
}
