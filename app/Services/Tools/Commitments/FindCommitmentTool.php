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

final class FindCommitmentTool implements JarvisTool
{
    public const NAME = 'find_commitment';

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
            description: 'Find first-class commitments by title, person, project, or expected result.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
                    'person_id' => ['type' => 'INTEGER'],
                    'project_id' => ['type' => 'INTEGER'],
                    'status' => ['type' => 'STRING'],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::COMMITMENTS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::COMMITMENTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $items = $this->commitments->list(
                $context->user,
                $query,
                isset($call->arguments['status']) ? (string) $call->arguments['status'] : null,
                isset($call->arguments['person_id']) ? (int) $call->arguments['person_id'] : null,
                isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
            );
        } catch (CommitmentException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'commitments' => $items->take(15)->map(fn (Commitment $commitment): array => $this->commitments->serializeSummary($commitment))->values()->all(),
        ]);
    }
}
