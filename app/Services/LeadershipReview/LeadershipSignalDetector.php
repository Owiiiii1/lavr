<?php

namespace App\Services\LeadershipReview;

use App\Enums\ExecutiveBriefPriority;
use App\Enums\LeadershipFindingSeverity;
use App\Enums\LeadershipReviewStatus;
use App\Enums\MeetingAnalysisStatus;
use App\Models\LeadershipReview;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ExecutiveBrief\ExecutiveBriefItem;
use Illuminate\Support\Facades\Schema;

final class LeadershipSignalDetector
{
    /**
     * @return list<ExecutiveBriefItem>
     */
    public function items(User $user): array
    {
        if (! Schema::hasTable('leadership_reviews')) {
            return [];
        }

        $fromReview = $this->fromLatestReview($user);
        if ($fromReview !== null) {
            return [$fromReview];
        }

        $repeated = $this->repeatedMeetingOwnerGap($user);

        return $repeated !== null ? [$repeated] : [];
    }

    private function fromLatestReview(User $user): ?ExecutiveBriefItem
    {
        $review = LeadershipReview::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [LeadershipReviewStatus::Ready, LeadershipReviewStatus::Partial])
            ->where('generated_at', '>=', now('UTC')->subDays(8))
            ->orderByDesc('id')
            ->first();

        if (! $review instanceof LeadershipReview) {
            return null;
        }

        $pack = is_array($review->findings_json) ? $review->findings_json : [];
        $attention = is_array($pack['attention'] ?? null) ? $pack['attention'] : [];
        foreach ($attention as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $severity = (string) ($finding['severity'] ?? '');
            $category = (string) ($finding['category'] ?? '');
            if (! in_array($severity, [LeadershipFindingSeverity::Critical->value, LeadershipFindingSeverity::High->value], true)) {
                continue;
            }
            if (! in_array($category, ['ownership', 'deadlines', 'follow_up', 'owner_dependency', 'bottlenecks', 'meeting_effectiveness'], true)) {
                continue;
            }

            $observation = trim((string) ($finding['observation'] ?? $finding['title'] ?? ''));
            if ($observation === '') {
                continue;
            }

            return new ExecutiveBriefItem(
                type: 'leadership_signal',
                priority: ExecutiveBriefPriority::High,
                title: (string) ($finding['title'] ?? 'Leadership'),
                summary: $observation,
                section: 'attention',
                dedupeKey: 'leadership:review:'.$review->id.':'.$category,
                confidence: (string) ($finding['confidence'] ?? 'medium'),
                score: 78,
                actionLabel: 'Open review',
                sourceType: 'leadership_review',
                sourceId: (int) $review->id,
                deepLink: '/lavr/leadership/'.$review->id,
            );
        }

        return null;
    }

    private function repeatedMeetingOwnerGap(User $user): ?ExecutiveBriefItem
    {
        if (! Schema::hasTable('meetings')) {
            return null;
        }

        $meetings = Meeting::query()
            ->with(['currentAnalysis', 'project'])
            ->where('user_id', $user->id)
            ->where('analysis_status', MeetingAnalysisStatus::Completed)
            ->whereNotNull('project_id')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $byProject = [];
        foreach ($meetings as $meeting) {
            $projectId = (int) $meeting->project_id;
            if ($projectId <= 0) {
                continue;
            }
            $byProject[$projectId][] = $meeting;
        }

        foreach ($byProject as $projectId => $rows) {
            $recent = array_slice($rows, 0, 3);
            if (count($recent) < 3) {
                continue;
            }
            $gapCount = 0;
            foreach ($recent as $meeting) {
                $result = is_array($meeting->currentAnalysis?->result_json) ? $meeting->currentAnalysis->result_json : [];
                $actions = is_array($result['action_items'] ?? null) ? $result['action_items'] : [];
                if ($actions === []) {
                    continue 2;
                }
                $without = 0;
                foreach ($actions as $action) {
                    if (! is_array($action) || trim((string) ($action['owner'] ?? '')) === '') {
                        $without++;
                    }
                }
                if ($without > 0 && ($without / max(1, count($actions))) >= 0.5) {
                    $gapCount++;
                }
            }

            if ($gapCount >= 3) {
                $first = $recent[0];
                $name = (string) ($first->project?->name ?: 'project');

                return new ExecutiveBriefItem(
                    type: 'leadership_signal',
                    priority: ExecutiveBriefPriority::High,
                    title: $name,
                    summary: '3 зустрічі поспіль по '.$name.' завершилися без чітких owner для action items.',
                    section: 'attention',
                    dedupeKey: 'leadership:meetings:'.$projectId,
                    confidence: 'high',
                    score: 82,
                    actionLabel: 'Open meetings',
                    sourceType: 'meeting',
                    sourceId: (int) $first->id,
                    projectId: (int) $first->project_id,
                    meetingId: (int) $first->id,
                    deepLink: '/lavr/leadership',
                );
            }
        }

        return null;
    }
}
