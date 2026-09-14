<?php

namespace App\Services\Watchers\Clients;

use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\IntegrationAccountResolver;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\GmailWatcherQuery;
use App\Services\Watchers\WatcherSchedule;
use App\Services\Watchers\WatcherSupport;
use Throwable;

final class LiveGmailWatcherClient implements GmailWatcherClient
{
    public function __construct(
        private readonly GoogleGmailService $gmail,
        private readonly IntegrationAccountService $accounts,
        private readonly IntegrationAccountResolver $resolver,
    ) {}

    public function search(User $user, array $source): array
    {
        $accounts = $this->resolveAccounts($user, $source);
        if ($accounts === [] || ! $user->canUseCapability(UserCapability::GMAIL)) {
            throw new IntegrationException('google_not_connected', 'Gmail is not connected.');
        }

        $query = GmailWatcherQuery::compile($source);
        if ($query === '') {
            $query = WatcherSchedule::isDigest($source) ? 'in:inbox' : '';
        }
        if ($query === '') {
            throw new IntegrationException('invalid_config', 'Gmail watchers need a sender, domain, subject, thread, or query.');
        }

        $rows = [];
        $healthy = 0;
        foreach ($accounts as $account) {
            try {
                $result = $this->gmail->searchMessages($account, $query, ['max_results' => 20]);
                $healthy++;
            } catch (Throwable) {
                continue;
            }

            foreach ($result['messages'] ?? [] as $message) {
                if (! is_array($message)) {
                    continue;
                }
                $rows[] = [
                    'id' => (string) ($message['id'] ?? ''),
                    'thread_id' => (string) ($message['thread_id'] ?? ($message['threadId'] ?? '')),
                    'sender' => (string) ($message['from'] ?? ($message['sender'] ?? '')),
                    'subject' => WatcherSupport::summary((string) ($message['subject'] ?? '')),
                    'occurred_at' => (string) ($message['date'] ?? ($message['internal_date'] ?? '')),
                    'account_id' => $account->id,
                ];
            }
        }

        if ($healthy === 0) {
            throw new IntegrationException('google_unavailable', 'Gmail is currently unavailable.');
        }

        return $rows;
    }

    public function snippet(User $user, array $source, string $messageId): array
    {
        $accounts = $this->resolveAccounts($user, $source);
        $account = $accounts[0] ?? null;
        if ($account === null) {
            throw new IntegrationException('google_not_connected', 'Gmail is not connected.');
        }

        $message = $this->gmail->getMessage($account, $messageId);

        return [
            'id' => (string) ($message['id'] ?? $messageId),
            'subject' => WatcherSupport::summary((string) ($message['subject'] ?? '')),
            'snippet' => WatcherSupport::summary((string) ($message['snippet'] ?? ($message['text'] ?? ''))),
            'sender' => (string) ($message['from'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<IntegrationAccount>
     */
    private function resolveAccounts(User $user, array $source): array
    {
        $accountId = isset($source['integration_account_id']) ? (int) $source['integration_account_id'] : 0;
        if ($accountId > 0) {
            $account = IntegrationAccount::query()
                ->where('user_id', $user->id)
                ->where('provider', 'google')
                ->whereKey($accountId)
                ->first();

            return $account !== null ? [$account] : [];
        }

        $projectId = isset($source['project_id']) ? (int) $source['project_id'] : 0;
        if ($projectId > 0) {
            $bound = $this->resolver->forProject($user, $projectId, 'gmail');
            if ($bound->isNotEmpty()) {
                return $bound->all();
            }
        }

        return $this->resolver->enabledFor($user, 'gmail')->all();
    }
}
