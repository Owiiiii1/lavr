<?php

namespace App\Services\Tools\Synthesis;

use App\Enums\SynthesisType;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\Exceptions\SynthesisException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetPersonStatusTool implements JarvisTool
{
    public const NAME = 'get_person_status';

    public function __construct(
        private readonly CrossSourceSynthesisService $synthesis,
        private readonly DirectoryService $directory,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Person status from canonical People first (roles, employee profile, projects, organizations), then Knowledge synthesis as fallback. Foreign ids fail.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'person_id' => ['type' => 'INTEGER', 'description' => 'Canonical people.id'],
                    'entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge person entity id.'],
                    'person' => ['type' => 'STRING', 'description' => 'Person name in People or the user’s Knowledge graph.'],
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
            && ($context->user->canUseCapability(UserCapability::PEOPLE)
                || $context->user->canUseCapability(UserCapability::KNOWLEDGE));
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $personId = isset($call->arguments['person_id']) ? (int) $call->arguments['person_id'] : 0;
        $entityId = isset($call->arguments['entity_id']) ? (int) $call->arguments['entity_id'] : 0;
        $name = trim((string) ($call->arguments['person'] ?? $call->arguments['name'] ?? ''));

        if ($personId < 1 && $entityId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        if ($context->user->canUseCapability(UserCapability::PEOPLE)) {
            try {
                $structured = $this->directory->personStatus(
                    $context->user,
                    $personId > 0 ? $personId : null,
                    $name !== '' ? $name : null,
                );
            } catch (DirectoryException) {
                $structured = null;
            }

            if (is_array($structured)) {
                $knowledge = null;

                if ($context->user->canUseCapability(UserCapability::KNOWLEDGE) && ($entityId > 0 || $name !== '')) {
                    try {
                        $knowledge = $this->synthesis->synthesize(new SynthesisScope(
                            user: $context->user,
                            type: SynthesisType::PersonStatus,
                            entityId: $entityId > 0 ? $entityId : null,
                            personName: $name !== '' ? $name : null,
                        ))->toArray();
                    } catch (SynthesisException) {
                        $knowledge = null;
                    }
                }

                return ToolResult::success($call->id, $this->name(), [
                    'success' => true,
                    ...$structured,
                    'knowledge' => $knowledge,
                ]);
            }
        }

        if ($entityId < 1 && $name === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'not_found',
            ]);
        }

        try {
            $result = $this->synthesis->synthesize(new SynthesisScope(
                user: $context->user,
                type: SynthesisType::PersonStatus,
                entityId: $entityId > 0 ? $entityId : null,
                personName: $name !== '' ? $name : null,
            ));
        } catch (SynthesisException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$result->toArray(),
        ]);
    }
}
