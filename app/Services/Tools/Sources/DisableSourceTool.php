<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\SourceDeletionService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class DisableSourceTool implements JarvisTool
{
    public const NAME = 'disable_source';

    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly SourceDeletionService $deletion,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Disable a Google source so it is no longer collected. Tokens stay until remove_source. Prefer this over revoke when the Owner wants a pause.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'account_id' => ['type' => 'INTEGER', 'description' => 'integration_accounts.id'],
                    'enabled' => ['type' => 'BOOLEAN', 'description' => 'Set false to disable, true to re-enable.'],
                ],
                'required' => ['account_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::INTEGRATIONS_ADMIN, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $account = $this->accounts->getAccount($context->user, (int) ($call->arguments['account_id'] ?? 0), 'google');
        $enabled = ($call->arguments['enabled'] ?? false) === true;
        $updated = $enabled
            ? $this->accounts->setEnabled($account, true)
            : $this->deletion->disable($context->user, $account);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'id' => $updated->id,
            'enabled' => $updated->enabled === true,
            'health' => $updated->health?->value,
        ]);
    }
}
