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
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;

final class OverdueCommitmentRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::OverdueCommitment->value;
    }

    public function evaluate(User $user): array
    {
        $now = CarbonImmutable::now('UTC');
        $rows = Commitment::query()
            ->with(['person:id,display_name'])
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->whereIn('lifecycle_status', [
                CommitmentLifecycleStatus::Open,
                CommitmentLifecycleStatus::LikelyDone,
            ])
            ->where('status', CommitmentEffectiveStatus::Overdue)
            ->orderBy('id')
            ->limit(80)
            ->get();

        $matches = [];
        foreach ($rows as $commitment) {
            $deadline = $commitment->deadline_at?->toImmutable();
            if ($deadline === null) {
                continue;
            }

            $hours = (int) abs($now->diffInHours($deadline));
            $severity = $this->severity((int) $hours);
            $who = $commitment->person?->display_name ?: (string) ($commitment->person_name_raw ?: 'Someone');
            $days = max(1, (int) ceil($hours / 24));

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::CommitmentOverdue,
                severity: $severity,
                fingerprint: OperationalFingerprint::make('commitment.overdue', (string) $commitment->id),
                rationale: $who.' missed "'.$commitment->title.'" by '.$days.' day(s).',
                proposalType: ProactiveProposalType::RemindPerson,
                title: 'Remind '.$who.'?',
                recommendedAction: 'remind_person',
                actionability: OperationalActionability::FollowUp,
                occurredAt: $deadline,
                evidence: [
                    'commitment_id' => $commitment->id,
                    'deadline_at' => $deadline->toIso8601String(),
                    'overdue_hours' => (int) $hours,
                ],
                payload: [
                    'overdue_hours' => (int) $hours,
                    'title' => $commitment->title,
                ],
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

    private function severity(int $hours): OperationalSeverity
    {
        $critical = max(1, (int) config('operational_control.overdue_critical_hours', 72));
        $high = max(1, (int) config('operational_control.overdue_high_hours', 24));

        if ($hours >= $critical) {
            return OperationalSeverity::Critical;
        }

        if ($hours >= $high) {
            return OperationalSeverity::High;
        }

        return OperationalSeverity::Normal;
    }
}
