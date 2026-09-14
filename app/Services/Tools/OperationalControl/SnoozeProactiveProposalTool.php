<?php

namespace App\Services\Tools\OperationalControl;

use App\Enums\ProactiveProposalStatus;
use App\Enums\ToolOperationClass;
use App\Models\ProactiveProposal;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\OperationalControl\ProactiveProposalExecutor;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class SnoozeProactiveProposalTool implements JarvisTool
{
    public const NAME = 'snooze_proactive_proposal';

    public function __construct(
        private readonly ProactiveProposalExecutor $executor,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Snooze a pending proactive proposal. when: later_today, tomorrow, or an ISO datetime.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'proposal_id' => ['type' => 'INTEGER'],
                    'when' => ['type' => 'STRING'],
                ],
                'required' => ['proposal_id', 'when'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::COMMITMENTS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->isOwner();
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = (int) ($call->arguments['proposal_id'] ?? 0);
        $proposal = ProactiveProposal::query()
            ->where('user_id', $context->user->id)
            ->whereKey($id)
            ->where('status', ProactiveProposalStatus::Pending)
            ->first();

        if ($proposal === null) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'not_found']);
        }

        $this->executor->snooze($context->user, $proposal, (string) $call->arguments['when']);

        return ToolResult::success($call->id, $this->name(), ['success' => true, 'proposal_id' => $proposal->id]);
    }
}
