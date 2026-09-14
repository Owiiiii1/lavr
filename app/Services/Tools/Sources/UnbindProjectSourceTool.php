<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Models\Project;
use App\Models\ProjectSourceBinding;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Sources\ProjectSourceBindingService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class UnbindProjectSourceTool implements JarvisTool
{
    public const NAME = 'unbind_project_source';

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
            description: 'Remove a Project source binding. Does not delete the Google account or Telegram group.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id.'],
                    'binding_id' => ['type' => 'INTEGER', 'description' => 'project_source_bindings.id'],
                ],
                'required' => ['project_id', 'binding_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::PROJECTS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::PROJECTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $project = Project::query()
            ->where('user_id', $context->user->id)
            ->whereKey((int) ($call->arguments['project_id'] ?? 0))
            ->first();
        $binding = ProjectSourceBinding::query()->whereKey((int) ($call->arguments['binding_id'] ?? 0))->first();

        if ($project === null || $binding === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $this->bindings->unbind($context->user, $project, $binding);
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), ['success' => true]);
    }
}
