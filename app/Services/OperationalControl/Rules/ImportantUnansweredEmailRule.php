<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Enums\SourceItemType;
use App\Models\IntegrationAccount;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class ImportantUnansweredEmailRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::ImportantUnansweredEmail->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('source_items')) {
            return [];
        }

        $hours = max(1, (int) config('operational_control.unanswered_email_hours', 24));
        $cutoff = CarbonImmutable::now('UTC')->subHours($hours);

        $items = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('source_type', SourceItemType::GmailMessage)
            ->where('occurred_at', '<=', $cutoff)
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $matches = [];
        foreach ($items as $item) {
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $explicit = ($metadata['explicit_request'] ?? false) === true
                || ($metadata['explicit_question'] ?? false) === true;
            $hasReply = ($metadata['thread_has_reply'] ?? false) === true;

            if (! $explicit || $hasReply) {
                continue;
            }

            if ($item->person_id === null || $item->project_id === null) {
                continue;
            }

            $accountUnknown = $this->mailboxUnknown($user, $item);
            if ($accountUnknown) {
                continue;
            }

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::EmailReplyExpected,
                severity: OperationalSeverity::High,
                fingerprint: OperationalFingerprint::make(
                    'email.reply_expected',
                    (string) $item->source_instance,
                    (string) ($item->thread_id ?: $item->external_id),
                ),
                rationale: 'Important unanswered email from a known person on a linked project.',
                proposalType: ProactiveProposalType::DraftEmail,
                title: 'Unanswered important email',
                recommendedAction: 'draft_email',
                actionability: OperationalActionability::FollowUp,
                occurredAt: $item->occurred_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: [
                    'source_item_id' => $item->id,
                    'thread_id' => $item->thread_id,
                    'subject' => $item->subject,
                ],
                payload: [
                    'subject' => $item->subject,
                    'source_instance' => $item->source_instance,
                ],
                sourceType: 'source_item',
                sourceId: (int) $item->id,
                sourceExternalId: (string) $item->external_id,
                personId: (int) $item->person_id,
                projectId: (int) $item->project_id,
                confidence: 'medium',
                evidencePointer: 'source_item:'.$item->id,
                href: '/lavr/proactive',
            );
        }

        return $matches;
    }

    private function mailboxUnknown(User $user, SourceItem $item): bool
    {
        if (! Schema::hasTable('integration_accounts')) {
            return true;
        }

        $query = IntegrationAccount::query()
            ->where('user_id', $user->id)
            ->where('provider', 'google');

        if ($item->integration_account_id) {
            $account = $query->whereKey($item->integration_account_id)->first();
            if ($account === null) {
                return true;
            }

            return $this->accountCannotProveReply($account);
        }

        $accounts = $query->get();
        if ($accounts->isEmpty()) {
            return true;
        }

        foreach ($accounts as $account) {
            if ($this->accountCannotProveReply($account)) {
                return true;
            }
        }

        return false;
    }

    private function accountCannotProveReply(IntegrationAccount $account): bool
    {
        if ($account->enabled !== true) {
            return true;
        }

        if (in_array($account->status, [
            IntegrationAccountStatus::Error,
            IntegrationAccountStatus::Revoked,
            IntegrationAccountStatus::Disconnected,
        ], true)) {
            return true;
        }

        if ($account->health === IntegrationHealth::Blocked) {
            return true;
        }

        return $account->last_error_code === 'blocked_auth';
    }
}
