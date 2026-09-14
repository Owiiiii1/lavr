<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Models\IntegrationAccount;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\CalendarTimeParser;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\IntegrationAccountResolver;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

abstract class GoogleCalendarTool implements JarvisTool
{
    public function __construct(
        protected readonly GoogleCalendarService $calendar,
        protected readonly IntegrationAccountService $accounts,
        protected readonly GoogleOAuthService $oauth,
        protected readonly CalendarTimeParser $times,
    ) {}

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::GOOGLE_CALENDAR);
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::GOOGLE_CALENDAR,
            operation: $this->operation(),
            provider: 'google',
            confirmationHint: $this->confirmationHint(),
        );
    }

    abstract protected function operation(): ToolOperationClass;

    protected function confirmationHint(): ?string
    {
        return null;
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
                    ->resolve($context->user, 'calendar', $accountId > 0 ? $accountId : null, $projectId > 0 ? $projectId : null);
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
        if (! $this->oauth->hasCalendarScope($scopes)) {
            throw new IntegrationException('calendar_scope_required', 'Calendar permission is required.');
        }

        return $account;
    }

    protected function calendarId(ToolCall $call): string
    {
        $calendarId = trim((string) ($call->arguments['calendar_id'] ?? ''));

        return $calendarId !== ''
            ? $calendarId
            : (string) config('google_calendar.default_calendar', 'primary');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function ok(ToolCall $call, array $payload): ToolResult
    {
        return ToolResult::success($call->id, $this->name(), array_merge(['success' => true], $payload));
    }

    protected function fail(ToolCall $call, string $error): ToolResult
    {
        return ToolResult::failure($call->id, $this->name(), [
            'success' => false,
            'error' => $error,
        ]);
    }

    protected function sendUpdates(ToolCall $call): string
    {
        $value = (string) ($call->arguments['send_updates'] ?? 'none');

        return in_array($value, ['all', 'externalOnly', 'none'], true) ? $value : 'none';
    }

    /**
     * @return list<string>
     */
    protected function attendees(ToolCall $call): array
    {
        $raw = $call->arguments['attendees'] ?? [];
        if (! is_array($raw)) {
            throw new IntegrationException('invalid_arguments', 'Attendees must be a list of emails.');
        }

        $max = (int) config('google_calendar.max_attendees', 20);
        if (count($raw) > $max) {
            throw new IntegrationException('invalid_arguments', 'Too many attendees.');
        }

        $emails = [];
        foreach ($raw as $item) {
            $email = strtolower(trim((string) $item));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new IntegrationException('invalid_arguments', 'Attendee email is invalid.');
            }

            $emails[] = $email;
        }

        return array_values(array_unique($emails));
    }

    protected function boundedText(mixed $value, int $max, bool $required = false): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            if ($required) {
                throw new IntegrationException('invalid_arguments', 'A required text field is empty.');
            }

            return null;
        }

        if (mb_strlen($text) > $max) {
            throw new IntegrationException('invalid_arguments', 'A text field exceeds the configured maximum.');
        }

        return $text;
    }
}
