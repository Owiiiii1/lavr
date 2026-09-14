<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\SourceDeletionService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class RemoveSourceTool implements JarvisTool
{
    public const NAME = 'remove_source';

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
            description: 'Revoke a Google account, delete credentials, unbind projects, and stop processing source items. Confirmed People/Projects/Commitments are kept. Requires confirmation.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'account_id' => ['type' => 'INTEGER', 'description' => 'integration_accounts.id to remove.'],
                ],
                'required' => ['account_id'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::INTEGRATIONS_ADMIN,
            operation: ToolOperationClass::Destructive,
            provider: 'google',
            confirmationHint: 'This disconnects the Google account and removes its source records. Confirmed business facts stay.',
            alwaysConfirm: true,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $accountId = (int) ($call->arguments['account_id'] ?? 0);
        if ($accountId < 1) {
            throw new IntegrationException('invalid_arguments', 'account_id is required.');
        }

        $account = $this->accounts->getAccount($context->user, $accountId, 'google');
        $this->deletion->removeGoogle($context->user, $account);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'removed_account_id' => $accountId,
        ]);
    }
}
