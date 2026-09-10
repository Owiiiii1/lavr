<?php

namespace App\Services\Tools\Synthesis;

use App\Enums\SynthesisType;
use App\Enums\ToolOperationClass;
use App\Models\Commitment;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListCommitmentsTool implements JarvisTool
{
    public const NAME = 'list_commitments';

    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
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
            description: 'Lists first-class commitments first (promises by a Person, not Tasks). Falls back to derived Knowledge only when the Owner has no first-class rows. mode=overdue|due_soon|open|detected|likely_done|confirmed|all.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'mode' => ['type' => 'STRING', 'description' => 'all | overdue | due_soon | open | detected | likely_done | confirmed | mine | others'],
                    'person_id' => ['type' => 'INTEGER'],
                    'project_id' => ['type' => 'INTEGER'],
                    'project' => ['type' => 'STRING'],
                    'query' => ['type' => 'STRING'],
                    'status' => ['type' => 'STRING'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::KNOWLEDGE, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && ($context->user->canUseCapability(UserCapability::COMMITMENTS)
                || $context->user->canUseCapability(UserCapability::KNOWLEDGE));
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $mode = mb_strtolower(trim((string) ($call->arguments['mode'] ?? 'all')));

        if ($context->user->canUseCapability(UserCapability::COMMITMENTS) && $this->commitments->hasFirstClass($context->user)) {
            $status = $this->statusFromMode($mode, $call->arguments['status'] ?? null);

            try {
                $items = $this->commitments->list(
                    $context->user,
                    isset($call->arguments['query']) ? (string) $call->arguments['query'] : null,
                    $status,
                    isset($call->arguments['person_id']) ? (int) $call->arguments['person_id'] : null,
                    isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                    null,
                    $mode === 'overdue',
                );
            } catch (CommitmentException $exception) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => $exception->error,
                ]);
            }

            return ToolResult::success($call->id, $this->name(), [
                'success' => true,
                'source' => 'first_class',
                'mode' => $mode,
                'commitments' => $items->map(fn (Commitment $commitment): array => $this->commitments->serializeSummary($commitment))->values()->all(),
            ]);
        }

        if (! in_array($mode, ['mine', 'others', 'all'], true)) {
            $mode = 'all';
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::Commitments,
                projectId: isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                projectName: isset($call->arguments['project']) ? trim((string) $call->arguments['project']) : null,
                commitmentMode: $mode,
                withNarrative: false,
            ));
        } catch (SynthesisException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'source' => 'knowledge_legacy',
            'mode' => $mode,
            'commitments' => $result->toArray()['commitments'] ?? [],
            'generated_at' => $result->generatedAt->toIso8601String(),
            'sources' => $result->sources,
        ]);
    }

    private function statusFromMode(string $mode, mixed $explicit): ?string
    {
        if (is_string($explicit) && trim($explicit) !== '') {
            return trim($explicit);
        }

        return in_array($mode, ['overdue', 'due_soon', 'open', 'detected', 'likely_done', 'confirmed'], true)
            ? $mode
            : null;
    }
}
