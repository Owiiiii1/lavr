<?php

namespace App\Services\Tools\Directory;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\DirectoryService;
use App\Services\Projects\ProjectService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class GetProjectTool implements JarvisTool
{
    public const NAME = 'get_project';

    public function __construct(
        private readonly ProjectService $projects,
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
            description: 'Load one Project business context: people, organizations, status. Does not invent meetings or commitments.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER'],
                ],
                'required' => ['project_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::PROJECTS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::PROJECTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = (int) ($call->arguments['project_id'] ?? 0);

        if ($id < 1) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        $project = $this->projects->findOwned($context->user, $id);

        if ($project === null) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'project_not_found']);
        }

        $project->load(['people.roles', 'organizations', 'ownerPerson']);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'project' => [
                ...$this->directory->serializeProjectCard($project),
                'people' => $project->people->map(fn ($person): array => $this->directory->serializePersonSummary($person))->values()->all(),
                'organizations' => $project->organizations->map(fn ($organization): array => $this->directory->serializeOrganization($organization))->values()->all(),
                'owner_person' => $project->ownerPerson ? [
                    'id' => $project->ownerPerson->id,
                    'display_name' => $project->ownerPerson->display_name,
                ] : null,
            ],
        ]);
    }
}
