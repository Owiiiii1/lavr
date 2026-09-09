<?php

namespace App\Services\Tools\Directory;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetPersonTool implements JarvisTool
{
    public const NAME = 'get_person';

    public function __construct(
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
            description: 'Load one canonical Person: roles, employee profile, organizations, projects, contacts. Does not invent facts.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'person_id' => ['type' => 'INTEGER', 'description' => 'Canonical people.id'],
                ],
                'required' => ['person_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::PEOPLE, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::PEOPLE);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = (int) ($call->arguments['person_id'] ?? 0);

        if ($id < 1) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $person = $this->directory->ownedPerson($context->user, $id)
                ->load(['roles', 'identities', 'employeeProfile.manager', 'projects', 'knowledgeEntities']);
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'person' => $this->directory->serializePerson($person),
        ]);
    }
}
