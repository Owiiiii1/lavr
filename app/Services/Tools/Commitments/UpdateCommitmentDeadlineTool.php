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

final class UpdateCommitmentDeadlineTool implements JarvisTool
{
    public const NAME = 'update_commitment_deadline';

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
            description: 'Update the deadline of an owned first-class commitment after explicit Owner request. Do not invent a datetime if only a vague phrase is known.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'commitment_id' => ['type' => 'INTEGER'],
                    'deadline_at' => ['type' => 'STRING'],
                    'deadline_raw' => ['type' => 'STRING'],
                    'deadline_precision' => ['type' => 'STRING'],
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
            confirmationHint: 'The Owner must confirm changing this deadline.',
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

            $updated = $this->commitments->update($context->user, $commitment, [
                'deadline_at' => $call->arguments['deadline_at'] ?? null,
                'deadline_raw' => $call->arguments['deadline_raw'] ?? null,
                'deadline_precision' => $call->arguments['deadline_precision'] ?? null,
            ]);
        } catch (CommitmentException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'commitment' => $this->commitments->serializeSummary($updated),
        ]);
    }
}
