<?php

namespace App\Services\Sources;

use App\Enums\IntegrationAccountStatus;
use App\Enums\ProjectSourceType;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;
use Illuminate\Support\Collection;

final class IntegrationAccountResolver
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly ProjectSourceBindingService $bindings,
        private readonly GoogleOAuthService $oauth,
    ) {}

    public function resolve(
        User $user,
        string $capability,
        ?int $accountId = null,
        ?int $projectId = null,
    ): IntegrationAccount {
        if ($accountId !== null && $accountId > 0) {
            $account = $this->accounts->getAccount($user, $accountId, 'google');
            $this->assertCapability($account, $capability);

            return $account;
        }

        if ($projectId !== null && $projectId > 0) {
            $bound = $this->forProject($user, $projectId, $capability);
            if ($bound->count() === 1) {
                return $bound->first();
            }
            if ($bound->count() > 1) {
                throw new IntegrationException(
                    'source_ambiguous',
                    'Several sources match this project. Choose an account.',
                    context: ['account_ids' => $bound->pluck('id')->all()],
                );
            }
        }

        $enabled = $this->enabledFor($user, $capability);
        if ($enabled->count() === 1) {
            return $enabled->first();
        }

        $fallback = $this->accounts->getActiveAccount($user, 'google');
        if ($fallback !== null) {
            try {
                $this->assertCapability($fallback, $capability);

                return $fallback;
            } catch (IntegrationException) {
            }
        }

        throw new IntegrationException('google_not_connected', 'Google is not connected.');
    }

    /**
     * @return Collection<int, IntegrationAccount>
     */
    public function enabledFor(User $user, string $capability): Collection
    {
        return $this->accounts->listEnabled($user, 'google')
            ->filter(fn (IntegrationAccount $account): bool => $this->hasCapability($account, $capability))
            ->values();
    }

    /**
     * @return Collection<int, IntegrationAccount>
     */
    public function forProject(User $user, int $projectId, string $capability): Collection
    {
        $types = $capability === 'calendar'
            ? [ProjectSourceType::GoogleCalendar, ProjectSourceType::IntegrationAccount]
            : [ProjectSourceType::GoogleMailbox, ProjectSourceType::IntegrationAccount];

        $ids = $this->bindings->sourceIdsForProject($user, $projectId, $types);
        if ($ids === []) {
            return collect();
        }

        return $this->enabledFor($user, $capability)
            ->filter(fn (IntegrationAccount $account): bool => in_array($account->id, $ids, true))
            ->values();
    }

    public function hasCapability(IntegrationAccount $account, string $capability): bool
    {
        if ($account->status !== IntegrationAccountStatus::Connected || $account->enabled !== true) {
            return false;
        }

        $scopes = is_array($account->scopes) ? $account->scopes : [];

        return match ($capability) {
            'gmail', UserCapability::GMAIL => $this->oauth->hasGmailReadScope($scopes) || $this->oauth->hasGmailScope($scopes),
            'calendar', UserCapability::GOOGLE_CALENDAR => $this->oauth->hasCalendarScope($scopes),
            default => true,
        };
    }

    private function assertCapability(IntegrationAccount $account, string $capability): void
    {
        if ($account->enabled !== true) {
            throw new IntegrationException('source_disabled', 'This source is disabled.');
        }

        if ($account->status !== IntegrationAccountStatus::Connected) {
            throw new IntegrationException('google_not_connected', 'Google is not connected.');
        }

        if (! $this->hasCapability($account, $capability)) {
            throw new IntegrationException(
                $capability === 'calendar' ? 'calendar_scope_required' : 'gmail_scope_required',
                $capability === 'calendar' ? 'Calendar permission is required.' : 'Gmail permission is required.',
            );
        }
    }
}
