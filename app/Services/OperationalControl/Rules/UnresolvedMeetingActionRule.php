<?php

namespace App\Services\OperationalControl\Rules;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalRuleKey;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\User;
use App\Services\OperationalControl\Contracts\OperationalRule;
use App\Services\OperationalControl\OperationalFingerprint;
use App\Services\OperationalControl\OperationalRuleMatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

final class UnresolvedMeetingActionRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::UnresolvedMeetingAction->value;
    }

    public function evaluate(User $user): array
    {
        if (! Schema::hasTable('meetings')) {
            return [];
        }

        $meetings = Meeting::query()
            ->with('currentAnalysis')
            ->where('user_id', $user->id)
            ->where('analysis_status', MeetingAnalysisStatus::Completed)
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        $matches = [];
        foreach ($meetings as $meeting) {
            $result = is_array($meeting->currentAnalysis?->result_json) ? $meeting->currentAnalysis->result_json : [];
            $risks = is_array($result['risks'] ?? null) ? $result['risks'] : [];
            $actions = is_array($result['action_items'] ?? null) ? $result['action_items'] : [];

            $hasCommitment = Commitment::query()
                ->where('user_id', $user->id)
                ->where('meeting_id', $meeting->id)
                ->whereNull('merged_into_id')
                ->exists();

            if ($risks !== []) {
                $text = (string) ($risks[0]['text'] ?? 'Meeting risk');
                $matches[] = new OperationalRuleMatch(
                    eventType: OperationalEventType::MeetingRiskDetected,
                    severity: OperationalSeverity::High,
                    fingerprint: OperationalFingerprint::make('meeting.risk', (string) $meeting->id),
                    rationale: 'Meeting "'.$meeting->title.'" has a recorded risk.',
                    proposalType: ProactiveProposalType::OpenSource,
                    title: 'Review meeting risk',
                    recommendedAction: 'open_source',
                    actionability: OperationalActionability::Review,
                    occurredAt: $meeting->updated_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                    evidence: ['meeting_id' => $meeting->id, 'risk' => mb_substr($text, 0, 180)],
                    payload: ['title' => $meeting->title],
                    sourceType: 'meeting',
                    sourceId: (int) $meeting->id,
                    projectId: $meeting->project_id ? (int) $meeting->project_id : null,
                    meetingId: (int) $meeting->id,
                    confidence: 'medium',
                    evidencePointer: 'meeting:'.$meeting->id,
                    href: '/lavr/meetings/'.$meeting->id,
                );
            }

            if ($actions !== [] && ! $hasCommitment) {
                $matches[] = new OperationalRuleMatch(
                    eventType: OperationalEventType::MeetingUnresolvedActions,
                    severity: OperationalSeverity::Normal,
                    fingerprint: OperationalFingerprint::make('meeting.unresolved_actions', (string) $meeting->id),
                    rationale: 'Meeting "'.$meeting->title.'" has action items without a related commitment.',
                    proposalType: ProactiveProposalType::OpenSource,
                    title: 'Unresolved meeting actions',
                    recommendedAction: 'open_source',
                    actionability: OperationalActionability::Review,
                    occurredAt: $meeting->updated_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                    evidence: ['meeting_id' => $meeting->id, 'action_count' => count($actions)],
                    payload: ['title' => $meeting->title],
                    sourceType: 'meeting',
                    sourceId: (int) $meeting->id,
                    projectId: $meeting->project_id ? (int) $meeting->project_id : null,
                    meetingId: (int) $meeting->id,
                    confidence: 'medium',
                    evidencePointer: 'meeting:'.$meeting->id,
                    href: '/lavr/meetings/'.$meeting->id,
                );
            }
        }

        return $matches;
    }
}
