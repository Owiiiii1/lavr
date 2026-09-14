<?php

namespace App\Services\Sources;

use App\Enums\IntegrationHealth;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use Throwable;

final class MultiAccountGmailSearch
{
    public function __construct(
        private readonly IntegrationAccountResolver $resolver,
        private readonly GoogleGmailService $gmail,
        private readonly SourceIngestService $ingest,
        private readonly IntegrationAccountService $accounts,
        private readonly SourceRouter $router,
        private readonly SourceIdentityResolver $identities,
    ) {}

    /**
     * @return array{
     *     messages: list<array<string, mixed>>,
     *     accounts: list<array<string, mixed>>,
     *     unavailable: list<array<string, mixed>>,
     *     semantics: string
     * }
     */
    public function search(
        User $user,
        string $query,
        ?int $accountId = null,
        ?int $projectId = null,
        int $maxResults = 15,
        bool $ingest = true,
    ): array {
        $accounts = $this->targetAccounts($user, $accountId, $projectId);
        if ($accounts === []) {
            return [
                'messages' => [],
                'accounts' => [],
                'unavailable' => [[
                    'reason' => 'google_not_connected',
                    'label' => 'Gmail',
                    'state' => 'unknown',
                ]],
                'semantics' => 'unknown',
            ];
        }

        $messages = [];
        $accountRows = [];
        $unavailable = [];

        foreach ($accounts as $account) {
            $label = $account->label();
            try {
                $result = $this->gmail->searchMessages($account, $query, ['max_results' => $maxResults]);
                $this->accounts->recordSuccess($account);
                $rows = is_array($result['messages'] ?? null) ? $result['messages'] : [];
                foreach ($rows as $message) {
                    if (! is_array($message)) {
                        continue;
                    }
                    if ($ingest) {
                        try {
                            $this->ingest->ingestGmail($user, $account, $message);
                        } catch (Throwable) {
                        }
                    }
                    $from = (string) ($message['from'] ?? $message['sender'] ?? '');
                    $identity = $this->identities->resolveEmail($user, $from);
                    $routed = $this->router->resolveProject($user, [
                        'source_type' => 'google_mailbox',
                        'source_id' => $account->id,
                        'thread_id' => $message['thread_id'] ?? null,
                        'person_id' => $identity['person_id'],
                        'title' => $message['subject'] ?? null,
                    ]);
                    $messages[] = [
                        'id' => $message['id'] ?? null,
                        'thread_id' => $message['thread_id'] ?? $message['threadId'] ?? null,
                        'account_id' => $account->id,
                        'account_label' => $label,
                        'account_email' => $account->external_account_email,
                        'from' => $from,
                        'subject' => $message['subject'] ?? null,
                        'snippet' => $message['snippet'] ?? null,
                        'date' => $message['date'] ?? $message['internal_date'] ?? null,
                        'project_id' => $routed['project_id'],
                        'person_id' => $identity['person_id'],
                    ];
                }
                $accountRows[] = [
                    'id' => $account->id,
                    'label' => $label,
                    'health' => $account->health instanceof IntegrationHealth ? $account->health->value : (string) $account->health,
                    'state' => 'ok',
                    'result_count' => count($rows),
                ];
            } catch (Throwable $exception) {
                $code = $exception instanceof IntegrationException ? $exception->error : 'gmail_unavailable';
                $this->accounts->recordError($account, $code);
                $unavailable[] = [
                    'account_id' => $account->id,
                    'label' => $label,
                    'reason' => $code,
                    'state' => 'unknown',
                ];
            }
        }

        $semantics = $unavailable !== [] && $messages === [] && $accountRows === []
            ? 'unknown'
            : ($messages === [] ? 'empty' : 'ok');

        if ($unavailable !== [] && $accountRows !== []) {
            $semantics = $messages === [] ? 'partial_empty' : 'partial';
        }

        return [
            'messages' => $messages,
            'accounts' => $accountRows,
            'unavailable' => $unavailable,
            'semantics' => $semantics,
        ];
    }

    /**
     * @return list<IntegrationAccount>
     */
    private function targetAccounts(User $user, ?int $accountId, ?int $projectId): array
    {
        if ($accountId !== null && $accountId > 0) {
            return [$this->resolver->resolve($user, 'gmail', $accountId, null)];
        }

        if ($projectId !== null && $projectId > 0) {
            $bound = $this->resolver->forProject($user, $projectId, 'gmail');
            if ($bound->isNotEmpty()) {
                return $bound->all();
            }
        }

        return $this->resolver->enabledFor($user, 'gmail')->all();
    }
}
