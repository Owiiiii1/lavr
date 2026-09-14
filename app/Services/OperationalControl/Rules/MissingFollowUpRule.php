<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Models\Commitment;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class MissingFollowUpRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::MissingFollowUp->value;
    }

    public function evaluate(User $user): array
    {
        $hours = max(1, (int) config('operational_control.missing_follow_up_hours', 48));
        $cutoff = CarbonImmutable::now('UTC')->subHours($hours);

        $rows = Commitment::query()
            ->with(['person:id,display_name'])
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->where('status', CommitmentEffectiveStatus::Overdue)
            ->whereIn('lifecycle_status', [CommitmentLifecycleStatus::Open])
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(40)
            ->get();

        $matches = [];
        foreach ($rows as $commitment) {
            if ($this->hasFollowUp($user, $commitment, $commitment->deadline_at->toImmutable())) {
                continue;
            }

            $who = $commitment->person?->display_name ?: (string) ($commitment->person_name_raw ?: 'Someone');

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::CommitmentOverdue,
                severity: OperationalSeverity::High,
                fingerprint: OperationalFingerprint::make('commitment.missing_followup', (string) $commitment->id),
                rationale: 'No follow-up after "'.$commitment->title.'" went overdue. Contact '.$who.'?',
                proposalType: ProactiveProposalType::ScheduleFollowup,
                title: 'Follow up with '.$who,
                recommendedAction: 'schedule_followup',
                actionability: OperationalActionability::FollowUp,
                occurredAt: $commitment->deadline_at->toImmutable(),
                evidence: ['commitment_id' => $commitment->id, 'quiet_after' => $cutoff->toIso8601String()],
                payload: ['title' => $commitment->title],
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id ? (int) $commitment->project_id : null,
                meetingId: $commitment->meeting_id ? (int) $commitment->meeting_id : null,
                commitmentId: (int) $commitment->id,
                confidence: 'high',
                evidencePointer: 'commitment:'.$commitment->id,
                href: '/lavr/commitments/'.$commitment->id,
            );
        }

        return $matches;
    }

    private function hasFollowUp(User $user, Commitment $commitment, CarbonImmutable $since): bool
    {
        if (! Schema::hasTable('source_items')) {
            return false;
        }

        $query = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('occurred_at', '>=', $since);

        if ($commitment->person_id) {
            $query->where('person_id', $commitment->person_id);
        } elseif ($commitment->project_id) {
            $query->where('project_id', $commitment->project_id);
        } else {
            return false;
        }

        return $query->exists();
    }
}
