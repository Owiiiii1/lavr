<?php

namespace App\Services\Tools\Commitments;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class CreateManualCommitmentTool implements JarvisTool
{
    public const NAME = 'create_manual_commitment';

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
            description: 'Create a manual first-class commitment after explicit Owner request. Status becomes open. Do not invent a person or deadline.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'title' => ['type' => 'STRING', 'description' => 'Short action title.'],
                    'person_id' => ['type' => 'INTEGER'],
                    'expected_result' => ['type' => 'STRING'],
                    'deadline_at' => ['type' => 'STRING', 'description' => 'ISO 8601 if known exactly.'],
                    'deadline_raw' => ['type' => 'STRING'],
                    'project_id' => ['type' => 'INTEGER'],
                    'notes' => ['type' => 'STRING'],
                ],
                'required' => ['title'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::COMMITMENTS,
            operation: ToolOperationClass::Write,
            provider: 'commitments',
            confirmationHint: 'The Owner must confirm creating this commitment.',
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::COMMITMENTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $commitment = $this->commitments->createManual($context->user, $call->arguments);
        } catch (CommitmentException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'commitment' => $this->commitments->serializeSummary($commitment),
        ]);
    }
}
