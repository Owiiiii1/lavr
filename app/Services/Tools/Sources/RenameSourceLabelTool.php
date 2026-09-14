<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class RenameSourceLabelTool implements JarvisTool
{
    public const NAME = 'rename_source_label';

    public function __construct(
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Rename a Google integration account human label, for example Chicago or Finance. Does not change the email.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'account_id' => ['type' => 'INTEGER', 'description' => 'integration_accounts.id'],
                    'label' => ['type' => 'STRING', 'description' => 'Human label such as Chicago.'],
                ],
                'required' => ['account_id', 'label'],
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
        $updated = $this->accounts->setLabel($account, (string) ($call->arguments['label'] ?? ''));

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'id' => $updated->id,
            'label' => $updated->label(),
        ]);
    }
}
