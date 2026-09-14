<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Sources\SourceDashboardService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListIntegrationsTool implements JarvisTool
{
    public const NAME = 'list_integrations';

    public function __construct(
        private readonly SourceDashboardService $dashboard,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists Owner Google accounts, Telegram groups, Zoom, and external source health/freshness. Use before mailbox or calendar search when the user asks what is connected. Does not reveal secrets or tokens.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'include_disabled' => [
                        'type' => 'BOOLEAN',
                        'description' => 'Include disabled Google accounts. Default true.',
                    ],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::INTEGRATIONS_ADMIN, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $payload = $this->dashboard->workspace($context->user);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$payload,
        ]);
    }
}
