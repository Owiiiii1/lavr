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
use Illuminate\Support\Str;

final class DecisionLikeUnresolvedRule implements OperationalRule
{
    public function key(): string
    {
        return OperationalRuleKey::DecisionLikeUnresolved->value;
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
            $decisions = is_array($result['decisions'] ?? null) ? $result['decisions'] : [];
            $actions = is_array($result['action_items'] ?? null) ? $result['action_items'] : [];

            if ($decisions === [] || $actions !== []) {
                continue;
            }

            $hasCommitment = Commitment::query()
                ->where('user_id', $user->id)
                ->where('meeting_id', $meeting->id)
                ->whereNull('merged_into_id')
                ->exists();

            if ($hasCommitment) {
                continue;
            }

            $text = mb_strtolower(trim((string) ($decisions[0]['text'] ?? '')));
            if ($text === '' || ! $this->topicRepeated($user, $meeting, $text)) {
                continue;
            }

            $matches[] = new OperationalRuleMatch(
                eventType: OperationalEventType::DecisionLikeUnresolved,
                severity: OperationalSeverity::Normal,
                fingerprint: OperationalFingerprint::make('decision_like.unresolved', (string) $meeting->id),
                rationale: 'A recorded decision has no next action.',
                proposalType: ProactiveProposalType::OpenSource,
                title: 'Decision has no next action',
                recommendedAction: 'open_source',
                actionability: OperationalActionability::Review,
                occurredAt: $meeting->updated_at?->toImmutable() ?? CarbonImmutable::now('UTC'),
                evidence: ['meeting_id' => $meeting->id],
                payload: ['title' => $meeting->title],
                sourceType: 'meeting',
                sourceId: (int) $meeting->id,
                projectId: $meeting->project_id ? (int) $meeting->project_id : null,
                meetingId: (int) $meeting->id,
                confidence: 'low',
                evidencePointer: 'meeting:'.$meeting->id,
                href: '/lavr/meetings/'.$meeting->id,
            );
        }

        return $matches;
    }

    private function topicRepeated(User $user, Meeting $meeting, string $text): bool
    {
        $needle = Str::of($text)->limit(40, '')->toString();
        if (mb_strlen($needle) < 8) {
            return false;
        }

        return Meeting::query()
            ->where('user_id', $user->id)
            ->where('id', '!=', $meeting->id)
            ->where(function ($query) use ($needle): void {
                $query->where('title', 'like', '%'.$needle.'%')
                    ->orWhere('summary', 'like', '%'.$needle.'%');
            })
            ->exists();
    }
}
