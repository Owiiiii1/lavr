<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Enums\SourceItemType;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class TelegramBlockerRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::TelegramBlocker->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('source_items')) {
            return [];
        }

        $items = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('source_type', SourceItemType::TelegramMessage)
            ->orderByDesc('id')
            ->limit(80)
            ->get();

        $matches = [];
        foreach ($items as $item) {
            $metadata = is_array($item->metadata) ? $item->metadata : [];
            $explicit = ($metadata['blocker'] ?? false) === true;
            $snippet = mb_strtolower((string) ($item->snippet ?? ''));
            $grounded = $explicit || preg_match('/\b(blocked|blocker|заблок|блокує|не можем|waiting on|залежимо)\b/u', $snippet) === 1;

            if (! $grounded) {
                continue;
            }

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::TelegramBlockerDetected,
                severity: OperationalSeverity::High,
                fingerprint: OperationalFingerprint::make('telegram.blocker', (string) $item->id),
                rationale: 'Telegram message reports a blocker.',
                proposalType: ProactiveProposalType::OpenSource,
                title: 'Blocker detected',
                recommendedAction: 'open_source',
                actionability: OperationalActionability::Review,
                occurredAt: $item->occurred_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: ['source_item_id' => $item->id],
                payload: ['subject' => $item->subject],
                sourceType: 'source_item',
                sourceId: (int) $item->id,
                sourceExternalId: (string) $item->external_id,
                personId: $item->person_id ? (int) $item->person_id : null,
                projectId: $item->project_id ? (int) $item->project_id : null,
                confidence: 'medium',
                evidencePointer: 'source_item:'.$item->id,
                href: '/lavr/proactive',
            );
        }

        return $matches;
    }
}
