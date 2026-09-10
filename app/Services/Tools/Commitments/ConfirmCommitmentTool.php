<?php

namespace App\Services\Tools\Commitments;

use App\Enums\ToolOperationClass;
use App\Models\Commitment;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ConfirmCommitmentTool implements JarvisTool
{
    public const NAME = 'confirm_commitment';

    public function __construct(
        private readonly CommitmentService $commitments,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Confirm a detected first-class commitment so it becomes open. Requires explicit Owner intent.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'commitment_id' => ['type' => 'INTEGER'],
                    'title' => ['type' => 'STRING'],
                    'expected_result' => ['type' => 'STRING'],
                    'person_id' => ['type' => 'INTEGER'],
                    'deadline_at' => ['type' => 'STRING'],
                    'deadline_raw' => ['type' => 'STRING'],
                ],
                'required' => ['commitment_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::COMMITMENTS,
            operation: ToolOperationClass::Write,
            provider: 'commitments',
            confirmationHint: 'The Owner must confirm this detected commitment.',
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::COMMITMENTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = (int) ($call->arguments['commitment_id'] ?? 0);

        if ($id < 1) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $commitment = Commitment::query()->find($id);

            if ($commitment === null) {
                throw new CommitmentException('not_found', 'Commitment not found.');
            }

            $updated = $this->commitments->confirmDetected($context->user, $commitment, $call->arguments);
        } catch (CommitmentException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'commitment' => $this->commitments->serializeSummary($updated),
        ]);
    }
}
