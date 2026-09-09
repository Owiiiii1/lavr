<?php

namespace App\Services\Tools\Meetings;

use App\Enums\ToolOperationClass;
use App\Models\Meeting;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Meetings\Exceptions\MeetingException;
use App\Services\Meetings\MeetingService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetMeetingAnalysisTool implements JarvisTool
{
    public const NAME = 'get_meeting_analysis';

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
            description: 'Load stored Meeting Intelligence: summary, decisions, action items, detected commitments, deadlines, questions, risks. Not first-class commitments.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'meeting_id' => ['type' => 'INTEGER'],
                ],
                'required' => ['meeting_id'],
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
        $id = (int) ($call->arguments['meeting_id'] ?? 0);

        if ($id < 1) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $meeting = Meeting::query()->find($id);

            if ($meeting === null) {
                throw new MeetingException('not_found', 'Meeting not found.');
            }

            $this->meetings->owned($context->user, $meeting);
            $analysis = $meeting->currentAnalysis ?? $meeting->analyses()->orderByDesc('version')->first();

            if ($analysis === null) {
                return ToolResult::success($call->id, $this->name(), [
                    'success' => true,
                    'analysis_status' => $meeting->analysis_status?->value ?? (string) $meeting->analysis_status,
                    'analysis' => null,
                ]);
            }
        } catch (MeetingException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'analysis_status' => $meeting->analysis_status?->value ?? (string) $meeting->analysis_status,
            'analysis' => $this->meetings->serializeAnalysis($analysis),
        ]);
    }
}
