<?php

namespace App\Services\LeadershipReview;

use App\Enums\LeadershipFindingCategory;
use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use App\Enums\OwnerLocale;
use App\Models\LeadershipReview;
use App\Models\Meeting;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class LeadershipReviewService
{
    public function __construct(
        private readonly LeadershipReviewGenerator $generator,
        private readonly LeadershipReviewCollector $collector,
        private readonly LeadershipReviewMetrics $metrics,
        private readonly LeadershipReviewFindings $findings,
    ) {}

    /**
     * @param  array{period?: string, from?: string, to?: string, review_type?: string, project_id?: int, person_id?: int, meeting_id?: int}  $input
     */
    public function generateNow(User $user, array $input = []): LeadershipReview
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $period = LeadershipReviewPeriod::fromInput($input, CarbonImmutable::now('UTC'), $timezone);
        $type = LeadershipReviewPeriod::typeFrom((string) ($input['review_type'] ?? 'owner'));
        $projectId = isset($input['project_id']) && (int) $input['project_id'] > 0 ? (int) $input['project_id'] : null;
        $personId = isset($input['person_id']) && (int) $input['person_id'] > 0 ? (int) $input['person_id'] : null;
        $meetingId = isset($input['meeting_id']) && (int) $input['meeting_id'] > 0 ? (int) $input['meeting_id'] : null;

        if ($type === LeadershipReviewType::Project && $projectId === null) {
            $type = LeadershipReviewType::Owner;
        }
        if ($type === LeadershipReviewType::Person && $personId === null) {
            $type = LeadershipReviewType::Owner;
        }
        if ($type === LeadershipReviewType::Meeting && $meetingId === null) {
            $type = LeadershipReviewType::Owner;
        }

        return $this->generator->generate(
            $user,
            $type,
            $period,
            'manual',
            $projectId,
            $personId,
            $meetingId,
            $this->deliveryFor($user),
        );
    }

    /**
     * @param  array{type?: string, status?: string, project_id?: int, person_id?: int, meeting_id?: int}  $filters
     * @return LengthAwarePaginator<int, LeadershipReview>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        $query = LeadershipReview::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id');

        $type = LeadershipReviewType::tryFrom((string) ($filters['type'] ?? ''));
        if ($type !== null) {
            $query->where('review_type', $type);
        }

        $status = LeadershipReviewStatus::tryFrom((string) ($filters['status'] ?? ''));
        if ($status !== null) {
            $query->where('status', $status);
        }

        if (! empty($filters['project_id'])) {
            $query->where('project_id', (int) $filters['project_id']);
        }
        if (! empty($filters['person_id'])) {
            $query->where('person_id', (int) $filters['person_id']);
        }
        if (! empty($filters['meeting_id'])) {
            $query->where('meeting_id', (int) $filters['meeting_id']);
        }

        return $query->paginate(40)->withQueryString();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(LeadershipReview $review): array
    {
        $metrics = is_array($review->metrics_json) ? $review->metrics_json : [];
        $pack = is_array($review->findings_json) ? $review->findings_json : [];
        $snapshot = is_array($review->source_snapshot_json) ? $review->source_snapshot_json : [];
        unset($snapshot['bodies'], $snapshot['emails'], $snapshot['transcripts']);

        $findings = is_array($pack['findings'] ?? null) ? $pack['findings'] : [];
        $sections = [
            'overview' => $this->section($findings, []),
            'strengths' => is_array($pack['strengths'] ?? null) ? $pack['strengths'] : [],
            'attention' => is_array($pack['attention'] ?? null) ? $pack['attention'] : [],
            'meetings' => $this->section($findings, [
                LeadershipFindingCategory::MeetingEffectiveness->value,
                LeadershipFindingCategory::Clarity->value,
            ]),
            'commitments' => $this->section($findings, [
                LeadershipFindingCategory::CommitmentReliability->value,
                LeadershipFindingCategory::Deadlines->value,
            ]),
            'delegation' => $this->section($findings, [
                LeadershipFindingCategory::Ownership->value,
                LeadershipFindingCategory::OwnerDependency->value,
            ]),
            'follow_up' => $this->section($findings, [
                LeadershipFindingCategory::FollowUp->value,
                LeadershipFindingCategory::DecisionFollowthrough->value,
            ]),
            'bottlenecks' => $this->section($findings, [
                LeadershipFindingCategory::Bottlenecks->value,
                LeadershipFindingCategory::WorkloadConcentration->value,
            ]),
        ];

        return [
            'id' => (int) $review->id,
            'review_type' => $review->review_type instanceof LeadershipReviewType ? $review->review_type->value : (string) $review->review_type,
            'status' => $review->status instanceof LeadershipReviewStatus ? $review->status->value : (string) $review->status,
            'origin' => (string) $review->origin,
            'summary' => (string) ($review->summary ?? ''),
            'metrics' => $this->publicMetrics($metrics),
            'findings' => $findings,
            'strengths' => $sections['strengths'],
            'attention' => $sections['attention'],
            'trends' => is_array($pack['trends'] ?? null) ? $pack['trends'] : [],
            'insufficient_trend' => (bool) ($pack['insufficient_trend'] ?? false),
            'sections' => $sections,
            'source_snapshot' => [
                'sources_attempted' => (int) ($snapshot['sources_attempted'] ?? 0),
                'sources_succeeded' => (int) ($snapshot['sources_succeeded'] ?? 0),
                'sources_failed' => (int) ($snapshot['sources_failed'] ?? 0),
                'errors' => is_array($snapshot['errors'] ?? null) ? array_values($snapshot['errors']) : [],
                'freshness' => is_array($snapshot['freshness'] ?? null) ? $snapshot['freshness'] : [],
                'data_through' => (string) ($snapshot['data_through'] ?? ''),
                'ai_used' => (bool) ($snapshot['ai_used'] ?? false),
            ],
            'period_start' => optional($review->period_start)?->toDateString(),
            'period_end' => optional($review->period_end)?->toDateString(),
            'generated_at' => optional($review->generated_at)?->toIso8601String(),
            'generated_by' => (string) $review->generated_by,
            'project_id' => $review->project_id,
            'person_id' => $review->person_id,
            'meeting_id' => $review->meeting_id,
            'href' => '/lavr/leadership/'.$review->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSummary(LeadershipReview $review): array
    {
        $pack = is_array($review->findings_json) ? $review->findings_json : [];
        $attention = is_array($pack['attention'] ?? null) ? $pack['attention'] : [];

        return [
            'id' => (int) $review->id,
            'review_type' => $review->review_type instanceof LeadershipReviewType ? $review->review_type->value : (string) $review->review_type,
            'status' => $review->status instanceof LeadershipReviewStatus ? $review->status->value : (string) $review->status,
            'summary' => (string) ($review->summary ?? ''),
            'top_findings' => array_slice(array_map(
                static fn (array $row): array => [
                    'title' => (string) ($row['title'] ?? ''),
                    'severity' => (string) ($row['severity'] ?? ''),
                    'category' => (string) ($row['category'] ?? ''),
                ],
                $attention,
            ), 0, 3),
            'period_start' => optional($review->period_start)?->toDateString(),
            'period_end' => optional($review->period_end)?->toDateString(),
            'generated_at' => optional($review->generated_at)?->toIso8601String(),
            'href' => '/lavr/leadership/'.$review->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operationalForPerson(User $user, Person $person, OwnerLocale $locale): array
    {
        $period = LeadershipReviewPeriod::fromInput(['period' => '30'], CarbonImmutable::now('UTC'), (string) ($user->timezone ?: 'UTC'));
        $collected = $this->collector->collect($user, $period, LeadershipReviewType::Person, null, (int) $person->id, null);
        $metrics = $this->metrics->compute($collected);
        $pack = $this->findings->build($collected, $metrics, null, $locale);

        return [
            'metrics' => $this->personMetrics($metrics, $collected, (int) $person->id),
            'findings' => array_values(array_filter(
                is_array($pack['attention'] ?? null) ? $pack['attention'] : [],
                static fn (array $row): bool => (int) ($row['person_id'] ?? 0) === (int) $person->id
                    || in_array((string) ($row['category'] ?? ''), ['follow_up', 'commitment_reliability', 'workload_concentration'], true),
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function operationalForProject(User $user, Project $project, OwnerLocale $locale): array
    {
        $period = LeadershipReviewPeriod::fromInput(['period' => '30'], CarbonImmutable::now('UTC'), (string) ($user->timezone ?: 'UTC'));
        $collected = $this->collector->collect($user, $period, LeadershipReviewType::Project, (int) $project->id, null, null);
        $metrics = $this->metrics->compute($collected);
        $pack = $this->findings->build($collected, $metrics, null, $locale);

        return [
            'metrics' => $this->publicMetrics($metrics),
            'findings' => is_array($pack['attention'] ?? null) ? $pack['attention'] : [],
            'strengths' => is_array($pack['strengths'] ?? null) ? $pack['strengths'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function meetingQuality(User $user, Meeting $meeting, OwnerLocale $locale): array
    {
        $period = LeadershipReviewPeriod::fromInput(['period' => '30'], CarbonImmutable::now('UTC'), (string) ($user->timezone ?: 'UTC'));
        $collected = $this->collector->collect($user, $period, LeadershipReviewType::Meeting, null, null, (int) $meeting->id);
        $metrics = $this->metrics->compute($collected);
        $pack = $this->findings->build($collected, $metrics, null, $locale);

        return [
            'metrics' => [
                'owner_coverage_pct' => $metrics['owner_coverage_pct'],
                'deadline_coverage_pct' => $metrics['deadline_coverage_pct'],
                'meeting_action_items' => (int) ($metrics['meeting_action_items'] ?? 0),
                'meeting_actions_without_owner' => (int) ($metrics['meeting_actions_without_owner'] ?? 0),
                'meeting_actions_without_deadline' => (int) ($metrics['meeting_actions_without_deadline'] ?? 0),
                'decision_like_items' => (int) ($metrics['decision_like_items'] ?? 0),
                'open_questions' => (int) ($metrics['open_questions'] ?? 0),
                'unresolved_identities' => (int) ($metrics['unresolved_identities'] ?? 0),
            ],
            'findings' => is_array($pack['attention'] ?? null) ? $pack['attention'] : [],
        ];
    }

    /**
     * @return array{telegram: bool, inbox: bool}
     */
    public function deliveryFor(User $user): array
    {
        $settings = $user->productivitySetting;

        return [
            'telegram' => (bool) ($settings?->leadership_review_telegram ?? true),
            'inbox' => (bool) ($settings?->leadership_review_inbox ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    private function publicMetrics(array $metrics): array
    {
        $keys = [
            'commitments_total', 'commitments_open', 'commitments_overdue', 'commitments_confirmed',
            'commitments_without_deadline', 'commitments_without_person', 'likely_done_unconfirmed',
            'detected_unreviewed', 'meeting_action_items', 'meeting_actions_without_owner',
            'meeting_actions_without_deadline', 'decision_like_items', 'reopened_topics',
            'projects_with_blockers', 'automation_blocked_count', 'owner_coverage_pct',
            'deadline_coverage_pct', 'commitment_confirmation_pct', 'overdue_ratio',
            'followup_gap_ratio', 'owner_dependency_share', 'meetings_total', 'meetings_analyzed',
            'sample_commitments', 'sample_meetings',
        ];

        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $metrics[$key] ?? null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $collected
     * @return array<string, mixed>
     */
    private function personMetrics(array $metrics, array $collected, int $personId): array
    {
        $total = 0;
        $overdue = 0;
        $open = 0;
        $confirmed = 0;
        $likely = 0;
        foreach (is_array($collected['commitments'] ?? null) ? $collected['commitments'] : [] as $row) {
            if (! is_array($row) || (int) ($row['person_id'] ?? 0) !== $personId) {
                continue;
            }
            $total++;
            $status = (string) ($row['status'] ?? '');
            if (in_array($status, ['open', 'due_soon', 'overdue', 'likely_done', 'detected'], true)) {
                $open++;
            }
            if ($status === 'overdue') {
                $overdue++;
            }
            if ($status === 'confirmed') {
                $confirmed++;
            }
            if ($status === 'likely_done') {
                $likely++;
            }
        }

        return [
            'commitments_total' => $total,
            'commitments_open' => $open,
            'commitments_overdue' => $overdue,
            'commitments_confirmed' => $confirmed,
            'likely_done_unconfirmed' => $likely,
            'workload_max_active' => (int) ($metrics['workload_person_id'] ?? 0) === $personId
                ? (int) ($metrics['workload_max_active'] ?? 0)
                : $open,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $categories
     * @return list<array<string, mixed>>
     */
    private function section(array $findings, array $categories): array
    {
        if ($categories === []) {
            return $findings;
        }

        return array_values(array_filter(
            $findings,
            static fn (array $row): bool => in_array((string) ($row['category'] ?? ''), $categories, true),
        ));
    }
}
