<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Models\TelegramGroup;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Sources\TelegramGroupSummaryService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;

final class SummarizeTelegramGroupTool implements JarvisTool
{
    public const NAME = 'summarize_telegram_group';

    public function __construct(
        private readonly TelegramGroupSummaryService $summaries,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Grounded summary of an owned Telegram group for a day: important updates, commitments, unanswered questions. Not a raw dump. Pass group_id or project_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'group_id' => ['type' => 'INTEGER', 'description' => 'telegram_groups.id'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'If set, uses the first Telegram group bound to the project.'],
                    'day' => ['type' => 'STRING', 'description' => 'Optional Y-m-d in the Owner timezone.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::TELEGRAM_GROUPS, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::TELEGRAM_GROUPS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $group = $this->findGroup($context, $call);
        if ($group === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $dayRaw = trim((string) ($call->arguments['day'] ?? ''));
        $day = $dayRaw !== ''
            ? CarbonImmutable::parse($dayRaw, $context->user->timezone ?: 'UTC')
            : CarbonImmutable::now($context->user->timezone ?: 'UTC');

        try {
            $summary = $this->summaries->summarize($context->user, $group, $day);
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$summary,
        ]);
    }

    private function findGroup(ToolExecutionContext $context, ToolCall $call): ?TelegramGroup
    {
        $groupId = isset($call->arguments['group_id']) ? (int) $call->arguments['group_id'] : 0;
        if ($groupId > 0) {
            return TelegramGroup::query()
                ->whereKey($groupId)
                ->whereHas('conversation', fn ($query) => $query->where('user_id', $context->user->id))
                ->first();
        }

        $projectId = isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : 0;
        if ($projectId < 1) {
            return null;
        }

        return TelegramGroup::query()
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $context->user->id))
            ->whereHas('projects', fn ($query) => $query->where('projects.id', $projectId))
            ->orderByDesc('last_message_at')
            ->first();
    }
}
