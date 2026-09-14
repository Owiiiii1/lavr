<?php

namespace App\Services\Sources\Connectors;

use App\Models\User;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\IntegrationAccountResolver;
use App\Services\Sources\MultiAccountGmailSearch;
use App\Services\Sources\SourceIdentityResolver;

final class GoogleMailboxConnector implements BusinessSourceConnector
{
    public function __construct(
        private readonly IntegrationAccountResolver $resolver,
        private readonly MultiAccountGmailSearch $search,
        private readonly SourceIdentityResolver $identities,
        private readonly IntegrationAccountService $accounts,
    ) {}

    public function key(): string
    {
        return 'google_mailbox';
    }

    public function health(User $user): array
    {
        $accounts = $this->resolver->enabledFor($user, 'gmail');
        $blocked = $this->accounts->listAccounts($user, 'google')
            ->filter(fn ($account): bool => $account->health?->value === 'blocked')
            ->count();

        return [
            'health' => $accounts->isEmpty() ? 'disabled' : ($blocked > 0 ? 'degraded' : 'healthy'),
            'freshness' => [
                'account_count' => $accounts->count(),
            ],
            'diagnostic' => $accounts->isEmpty() ? 'No Gmail accounts connected.' : null,
        ];
    }

    public function collect(User $user, array $options = []): array
    {
        return $this->search->search(
            $user,
            (string) ($options['query'] ?? 'in:inbox'),
            isset($options['account_id']) ? (int) $options['account_id'] : null,
            isset($options['project_id']) ? (int) $options['project_id'] : null,
        );
    }

    public function normalize(User $user, array $raw): array
    {
        return [
            'external_id' => $raw['id'] ?? null,
            'subject' => $raw['subject'] ?? null,
            'snippet' => $raw['snippet'] ?? null,
            'occurred_at' => $raw['date'] ?? null,
        ];
    }

    public function resolveIdentities(User $user, array $raw): array
    {
        $from = (string) ($raw['from'] ?? '');
        $resolved = $this->identities->resolveEmail($user, $from);

        return [
            'person_id' => $resolved['person_id'],
            'unresolved' => (bool) ($resolved['unresolved'] ?? false),
        ];
    }

    public function sourceReference(array $raw): array
    {
        return [
            'source_type' => 'gmail_message',
            'external_id' => $raw['id'] ?? null,
            'account_id' => $raw['account_id'] ?? null,
        ];
    }
}
