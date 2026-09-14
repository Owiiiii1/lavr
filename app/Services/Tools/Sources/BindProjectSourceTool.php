<?php

namespace App\Services\Tools\Sources;

use App\Enums\ProjectSourceType;
use App\Enums\SourceBindingKind;
use App\Enums\ToolOperationClass;
use App\Models\Project;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Sources\ProjectSourceBindingService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class BindProjectSourceTool implements JarvisTool
{
    public const NAME = 'bind_project_source';

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
            description: 'Explicitly bind a source instance (Gmail account, calendar, Telegram group) to an owned Project. Never bind silently from a suggestion without the Owner asking.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id.'],
                    'source_type' => ['type' => 'STRING', 'description' => 'google_mailbox, google_calendar, telegram_group, zoom, external_api.'],
                    'source_id' => ['type' => 'INTEGER', 'description' => 'Source instance id (integration_accounts.id or telegram_groups.id).'],
                    'purpose' => ['type' => 'STRING', 'description' => 'Optional purpose label.'],
                ],
                'required' => ['project_id', 'source_type', 'source_id'],
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
        $type = ProjectSourceType::tryFrom((string) ($call->arguments['source_type'] ?? ''));
        $sourceId = (int) ($call->arguments['source_id'] ?? 0);

        if ($project === null || $type === null || $sourceId < 1) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        try {
            $binding = $this->bindings->bind(
                $context->user,
                $project,
                $type,
                $sourceId,
                SourceBindingKind::Explicit,
                isset($call->arguments['purpose']) ? (string) $call->arguments['purpose'] : null,
            );
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'binding' => $this->bindings->serialize($context->user, $binding),
        ]);
    }
}
