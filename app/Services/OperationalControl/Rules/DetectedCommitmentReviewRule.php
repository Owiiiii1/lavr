<?php

namespace App\Services\OperationalControl\Rules;

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

final class DetectedCommitmentReviewRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::DetectedCommitmentReview->value;
    }

    public function evaluate(User $user): array
    {
        $rows = Commitment::query()
            ->with(['person:id,display_name'])
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->where('lifecycle_status', CommitmentLifecycleStatus::Detected)
            ->orderBy('id')
            ->limit(40)
            ->get();

        $matches = [];
        foreach ($rows as $commitment) {
            $who = $commitment->person?->display_name ?: (string) ($commitment->person_name_raw ?: 'Someone');

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::CommitmentDetected,
                severity: OperationalSeverity::Normal,
                fingerprint: OperationalFingerprint::make('commitment.detected', (string) $commitment->id),
                rationale: 'Detected commitment from '.$who.': "'.$commitment->title.'". Review before it becomes active.',
                proposalType: ProactiveProposalType::ReviewDetectedCommitment,
                title: 'Review detected commitment',
                recommendedAction: 'review_detected_commitment',
                actionability: OperationalActionability::Review,
                occurredAt: $commitment->detected_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: ['commitment_id' => $commitment->id],
                payload: ['title' => $commitment->title],
                sourceType: 'commitment',
                sourceId: (int) $commitment->id,
                personId: $commitment->person_id ? (int) $commitment->person_id : null,
                projectId: $commitment->project_id ? (int) $commitment->project_id : null,
                meetingId: $commitment->meeting_id ? (int) $commitment->meeting_id : null,
                commitmentId: (int) $commitment->id,
                confidence: 'medium',
                evidencePointer: 'commitment:'.$commitment->id,
                href: '/lavr/commitments/'.$commitment->id,
            );
        }

        return $matches;
    }
}
