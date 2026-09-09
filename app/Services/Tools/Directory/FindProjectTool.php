<?php

namespace App\Services\Tools\Directory;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\DirectoryService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class FindProjectTool implements JarvisTool
{
    public const NAME = 'find_project';

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
            description: 'Find Owner Projects (business contexts) by name. Use for Chicago, Miami, and other show names.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
                ],
                'required' => ['query'],
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
        $query = trim((string) ($call->arguments['query'] ?? $call->arguments['project'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        $projects = $this->directory->findProjects($context->user, $query);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'projects' => $projects->map(fn ($project): array => $this->directory->serializeProjectCard($project))->values()->all(),
        ]);
    }
}
