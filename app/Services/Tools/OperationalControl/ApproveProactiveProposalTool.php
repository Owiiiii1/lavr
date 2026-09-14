<?php

namespace App\Services\Tools\OperationalControl;

use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Enums\ToolOperationClass;
use App\Models\Person;
use App\Models\ProactiveProposal;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\OperationalControl\ProactiveProposalExecutor;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ApproveProactiveProposalTool implements JarvisTool
{
    public const NAME = 'approve_proactive_proposal';

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
            description: 'Approve one pending proactive proposal. Resolve a unique proposal_id, or a unique pending remind/confirm proposal for a named person. Does not send third-party messages unless send=true and identity is unique. Ambiguous recipients are rejected.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'proposal_id' => ['type' => 'INTEGER'],
                    'person' => ['type' => 'STRING', 'description' => 'Person display name to resolve a unique pending proposal.'],
                    'send' => ['type' => 'BOOLEAN', 'description' => 'If true, attempt an external send after identity checks. Default false.'],
                    'draft_body' => ['type' => 'STRING'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::COMMITMENTS,
            operation: ToolOperationClass::Write,
            provider: 'operational_control',
            confirmationHint: 'Approve this proactive action. External send still requires unique identity and policy.',
            alwaysConfirm: true,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->isOwner();
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $proposal = $this->resolve($call, $context);
        if ($proposal === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'ambiguous_or_missing_proposal',
            ]);
        }

        $explicitSend = (bool) ($call->arguments['send'] ?? false) || $context->explicitUserCommand === true;
        $result = $this->executor->approve(
            $context->user,
            $proposal,
            ['draft_body' => $call->arguments['draft_body'] ?? null],
            $explicitSend,
        );

        if (! $result['ok']) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $result['error'],
                'status' => $result['status'],
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'status' => $result['status'],
            'sent' => $result['sent'],
            'proposal_id' => $proposal->id,
            'href' => '/lavr/proactive/'.$proposal->id,
        ]);
    }

    private function resolve(ToolCall $call, ToolExecutionContext $context): ?ProactiveProposal
    {
        $id = (int) ($call->arguments['proposal_id'] ?? 0);
        if ($id > 0) {
            $proposal = ProactiveProposal::query()
                ->where('user_id', $context->user->id)
                ->whereKey($id)
                ->first();

            return $proposal?->status === ProactiveProposalStatus::Pending ? $proposal : null;
        }

        $name = trim((string) ($call->arguments['person'] ?? ''));
        if ($name === '') {
            $pending = ProactiveProposal::query()
                ->where('user_id', $context->user->id)
                ->where('status', ProactiveProposalStatus::Pending)
                ->orderByDesc('id')
                ->limit(2)
                ->get();

            return $pending->count() === 1 ? $pending->first() : null;
        }

        $normalized = ProjectNameNormalizer::normalize($name);
        $people = Person::query()
            ->where('user_id', $context->user->id)
            ->where('normalized_name', $normalized)
            ->get();

        if ($people->count() !== 1) {
            return null;
        }

        $pending = ProactiveProposal::query()
            ->where('user_id', $context->user->id)
            ->where('person_id', $people->first()->id)
            ->where('status', ProactiveProposalStatus::Pending)
            ->whereIn('proposal_type', [ProactiveProposalType::RemindPerson, ProactiveProposalType::ConfirmCommitment])
            ->orderByDesc('id')
            ->limit(2)
            ->get();

        return $pending->count() === 1 ? $pending->first() : null;
    }
}
