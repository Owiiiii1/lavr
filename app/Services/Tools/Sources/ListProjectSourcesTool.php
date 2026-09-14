<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Models\Project;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Sources\ProjectSourceBindingService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListProjectSourcesTool implements JarvisTool
{
    public const NAME = 'list_project_sources';

    public function __construct(
        private readonly ProjectSourceBindingService $bindings,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists sources bound to one owned Project: Gmail mailboxes, calendars, Telegram groups. Use when the user asks what is connected to Chicago / Milan / a named project.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id.'],
                    'project' => ['type' => 'STRING', 'description' => 'Project name if id is unknown.'],
                ],
                'required' => [],
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
        $project = $this->findProject($context, $call);
        if ($project === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'project_id' => $project->id,
            'sources' => $this->bindings->serializeForProject($context->user, $project),
        ]);
    }

    private function findProject(ToolExecutionContext $context, ToolCall $call): ?Project
    {
        $id = isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : 0;
        if ($id > 0) {
            return Project::query()->where('user_id', $context->user->id)->whereKey($id)->first();
        }

        $name = trim((string) ($call->arguments['project'] ?? ''));
        if ($name === '') {
            return null;
        }

        return Project::query()
            ->where('user_id', $context->user->id)
            ->where('name', $name)
            ->first();
    }
}
