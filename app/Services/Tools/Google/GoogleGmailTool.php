<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Models\IntegrationAccount;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GmailAddressValidator;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\IntegrationAccountResolver;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

abstract class GoogleGmailTool implements JarvisTool
{
    public function __construct(
        protected readonly GoogleGmailService $gmail,
        protected readonly IntegrationAccountService $accounts,
        protected readonly GoogleOAuthService $oauth,
        protected readonly GmailAddressValidator $addresses,
    ) {}

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::GMAIL);
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::GMAIL,
            operation: $this->operation(),
            provider: 'google',
            confirmationHint: $this->confirmationHint(),
            alwaysConfirm: $this->alwaysConfirm(),
        );
    }

    abstract protected function operation(): ToolOperationClass;

    protected function confirmationHint(): ?string
    {
        return null;
    }

    protected function alwaysConfirm(): bool
    {
        return false;
    }

    public function assertReady(ToolExecutionContext $context): void
    {
        $this->resolveAccount($context);
    }

    protected function resolveAccount(ToolExecutionContext $context, ?ToolCall $call = null): IntegrationAccount
    {
        $accountId = $call !== null ? (int) ($call->arguments['account_id'] ?? $call->arguments['integration_account_id'] ?? 0) : 0;
        $projectId = $call !== null ? (int) ($call->arguments['project_id'] ?? 0) : 0;

        try {
            if ($accountId > 0 || $projectId > 0) {
                return app(IntegrationAccountResolver::class)
                    ->resolve($context->user, 'gmail', $accountId > 0 ? $accountId : null, $projectId > 0 ? $projectId : null);
            }

            $account = $this->accounts->getActiveAccount($context->user, 'google');
        } catch (IntegrationException $exception) {
            if ($exception->error === 'forbidden') {
                throw new IntegrationException('google_not_connected', 'Google is not connected.');
            }

            throw $exception;
        }

        if ($account === null) {
            throw new IntegrationException('google_not_connected', 'Google is not connected.');
        }

        $scopes = is_array($account->scopes) ? $account->scopes : [];
        if (! $this->hasRequiredScope($scopes)) {
            throw new IntegrationException('gmail_scope_required', 'Gmail permission is required.');
        }

        return $account;
    }

    /**
     * @param  list<string>  $scopes
     */
    protected function hasRequiredScope(array $scopes): bool
    {
        return $this->oauth->hasGmailReadScope($scopes);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(ToolCall $call, array $payload): ToolResult
    {
        return ToolResult::success($call->id, $this->name(), array_merge(['success' => true], $payload));
    }
}
