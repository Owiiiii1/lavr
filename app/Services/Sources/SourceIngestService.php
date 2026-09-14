<?php

namespace App\Services\Sources;

use App\Enums\CommitmentSourceType;
use App\Enums\ConversationKind;
use App\Enums\ProjectSourceType;
use App\Enums\SourceItemType;
use App\Models\IntegrationAccount;
use App\Models\Message;
use App\Models\SourceItem;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Commitments\CommitmentPromotionService;
use App\Services\Integrations\IntegrationAccountService;
use Carbon\CarbonImmutable;

final class SourceIngestService
{
    public function __construct(
        private readonly SourceItemService $items,
        private readonly SourceIdentityResolver $identities,
        private readonly SourceRouter $router,
        private readonly CommunicationCommitmentExtractor $extractor,
        private readonly CommunicationCompletionService $completion,
        private readonly SourceCorrelationService $correlation,
        private readonly CommitmentPromotionService $promotion,
        private readonly IntegrationAccountService $accounts,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     */
    public function ingestGmail(User $user, IntegrationAccount $account, array $message): SourceItem
    {
        $externalId = (string) ($message['id'] ?? '');
        $from = $this->emailFromHeader((string) ($message['from'] ?? $message['sender'] ?? ''));
        $identity = $from !== '' ? $this->identities->resolveEmail($user, $from) : [
            'person_id' => null,
            'unresolved' => false,
            'value' => '',
        ];
        $text = trim((string) ($message['snippet'] ?? $message['subject'] ?? ''));
        $subject = (string) ($message['subject'] ?? '');
        $routed = $this->router->resolveProject($user, [
            'source_type' => ProjectSourceType::GoogleMailbox,
            'source_id' => $account->id,
            'thread_id' => $message['thread_id'] ?? $message['threadId'] ?? null,
            'person_id' => $identity['person_id'],
            'title' => $subject,
        ]);

        $item = $this->items->upsert($user, SourceItemType::GmailMessage, [
            'integration_account_id' => $account->id,
            'source_instance' => 'integration:'.$account->id,
            'external_id' => $externalId,
            'thread_id' => $message['thread_id'] ?? $message['threadId'] ?? null,
            'occurred_at' => $message['date'] ?? $message['internal_date'] ?? now(),
            'person_id' => $identity['person_id'],
            'project_id' => $routed['project_id'],
            'subject' => $subject,
            'snippet' => $text,
            'metadata' => [
                'sender' => $from,
                'unresolved_identity' => $identity['unresolved'] ?? false,
                'identity_value' => $identity['value'] ?? null,
                'routing' => $routed,
            ],
            'content_hash' => hash('sha256', $account->id.'|'.$externalId.'|'.$text),
        ]);

        $this->accounts->recordEvent($account);
        $this->applyOperationalFacts($user, $item, $text.' '.$subject, CommitmentSourceType::Email, (bool) ($identity['unresolved'] ?? false));

        return $this->items->markProcessed($item);
    }

    public function ingestTelegramMessage(User $user, TelegramGroup $group, Message $message, bool $fromOwner = false): ?SourceItem
    {
        if (! $this->groupMonitored($group)) {
            return null;
        }

        $telegramUserId = (string) ($message->sender_external_id ?? '');
        $username = (string) ($message->sender_username ?? '');
        $identity = ['person_id' => null, 'unresolved' => false, 'value' => ''];
        if ($telegramUserId !== '') {
            $identity = $this->identities->resolveTelegramUserId($user, $telegramUserId);
        }
        if (($identity['person_id'] ?? null) === null && $username !== '') {
            $identity = $this->identities->resolveTelegramUsername($user, $username);
        }

        $text = trim((string) $message->body);
        $routed = $this->router->resolveProject($user, [
            'source_type' => ProjectSourceType::TelegramGroup,
            'source_id' => $group->id,
            'person_id' => $identity['person_id'],
            'title' => $group->title,
        ]);

        $item = $this->items->upsert($user, SourceItemType::TelegramMessage, [
            'source_instance' => 'telegram_group:'.$group->id,
            'external_id' => (string) ($message->channel_message_id ?: $message->id),
            'occurred_at' => $message->occurred_at ?? now(),
            'person_id' => $identity['person_id'],
            'project_id' => $routed['project_id'],
            'subject' => $group->title,
            'snippet' => $text,
            'metadata' => [
                'telegram_group_id' => $group->id,
                'telegram_user_id' => $telegramUserId,
                'telegram_username' => $username,
                'unresolved_identity' => $identity['unresolved'] ?? false,
                'routing' => $routed,
            ],
            'content_hash' => hash('sha256', $group->id.'|'.$message->id.'|'.$text),
        ]);

        $ownerCommand = $fromOwner || $this->isOwnerCommand($message, $fromOwner);
        $this->applyOperationalFacts($user, $item, $text, CommitmentSourceType::Telegram, $ownerCommand || ($identity['person_id'] ?? null) === null);

        return $this->items->markProcessed($item);
    }

    private function applyOperationalFacts(
        User $user,
        SourceItem $item,
        string $text,
        CommitmentSourceType $sourceType,
        bool $skipCandidate,
    ): void {
        $existing = $this->correlation->findCommitment($user, [
            'person_id' => $item->person_id,
            'project_id' => $item->project_id,
            'text' => $text,
            'occurred_at' => $item->occurred_at?->toImmutable(),
        ]);

        if ($existing !== null) {
            $this->completion->apply(
                $existing,
                $text,
                $sourceType,
                $item->id,
                $this->items->provenance($item),
            );

            return;
        }

        if ($skipCandidate) {
            return;
        }

        $candidate = $this->extractor->extract($user, [
            'text' => $text,
            'source_type' => $sourceType,
            'source_id' => $item->id,
            'source_reference' => $this->items->provenance($item),
            'person_id' => $item->person_id,
            'project_id' => $item->project_id,
            'occurred_at' => $item->occurred_at?->toImmutable() ?? CarbonImmutable::now(),
            'is_owner_command' => false,
        ]);

        if ($candidate !== null) {
            $this->promotion->promote($candidate);
        }
    }

    private function groupMonitored(TelegramGroup $group): bool
    {
        $settings = is_array($group->settings) ? $group->settings : [];
        if (($settings['monitoring_enabled'] ?? false) === true) {
            return true;
        }

        return $group->projects()->exists();
    }

    private function isOwnerCommand(Message $message, bool $fromOwner): bool
    {
        if ($fromOwner) {
            return true;
        }

        $conversation = $message->conversation;
        if ($conversation !== null && $conversation->kind === ConversationKind::Personal) {
            return true;
        }

        $body = mb_strtolower(trim((string) $message->body));

        return preg_match('/^(lavr|jarvis|,|\/)/u', $body) === 1;
    }

    private function emailFromHeader(string $raw): string
    {
        if (preg_match('/<([^>]+)>/', $raw, $matches) === 1) {
            return mb_strtolower(trim($matches[1]));
        }

        return mb_strtolower(trim($raw));
    }
}
