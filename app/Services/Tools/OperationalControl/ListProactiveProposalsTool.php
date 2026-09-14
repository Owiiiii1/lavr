<?php

namespace App\Services\Tools\OperationalControl;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\OperationalControl\ProactiveProposalService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListProactiveProposalsTool implements JarvisTool
{
    public const NAME = 'list_proactive_proposals';

    public function __construct(
        private readonly ProactiveProposalService $proposals,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists pending proactive operational proposals that need Owner attention. Use when the Owner asks what needs attention, what to do next, or to review LAVR suggestions. Prefer this over guessing from chat.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'person_id' => ['type' => 'INTEGER'],
                    'project_id' => ['type' => 'INTEGER'],
                    'commitment_id' => ['type' => 'INTEGER'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::COMMITMENTS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->isOwner();
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $personId = (int) ($call->arguments['person_id'] ?? 0);
        $projectId = (int) ($call->arguments['project_id'] ?? 0);
        $commitmentId = (int) ($call->arguments['commitment_id'] ?? 0);

        $rows = match (true) {
            $personId > 0 => $this->proposals->pendingForPerson($context->user, $personId),
            $projectId > 0 => $this->proposals->pendingForProject($context->user, $projectId),
            $commitmentId > 0 => $this->proposals->pendingForCommitment($context->user, $commitmentId),
            default => $this->proposals->pendingForUser($context->user, 12),
        };

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'href' => '/lavr/proactive',
            'proposals' => array_map(fn ($proposal): array => $this->proposals->serialize($proposal), $rows),
        ]);
    }
}
