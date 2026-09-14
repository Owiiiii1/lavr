<?php

namespace App\Services\LeadershipReview;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\LeadershipReviewType;
use App\Enums\MeetingAnalysisStatus;
use App\Models\AutomationRun;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class LeadershipReviewCollector
{
    /**
     * @return array{
     *     commitments: list<array<string, mixed>>,
     *     meetings: list<array<string, mixed>>,
     *     projects: list<array<string, mixed>>,
     *     people: list<array<string, mixed>>,
     *     automation_runs: list<array<string, mixed>>,
     *     owner_person_ids: list<int>,
     *     owner_name: string,
     *     snapshot: array<string, mixed>
     * }
     */
    public function collect(
        User $user,
        LeadershipReviewPeriod $period,
        LeadershipReviewType $type,
        ?int $projectId = null,
        ?int $personId = null,
        ?int $meetingId = null,
    ): array {
        $attempted = 0;
        $succeeded = 0;
        $failed = 0;
        $errors = [];
        $freshness = [];
        $commitments = [];
        $meetings = [];
        $projects = [];
        $people = [];
        $automations = [];

        $sources = [
            'commitments' => function () use ($user, $period, $type, $projectId, $personId, $meetingId): array {
                return $this->commitments($user, $period, $type, $projectId, $personId, $meetingId);
            },
            'meetings' => function () use ($user, $period, $type, $projectId, $personId, $meetingId): array {
                return $this->meetings($user, $period, $type, $projectId, $personId, $meetingId);
            },
            'projects' => function () use ($user, $type, $projectId): array {
                return $this->projects($user, $type, $projectId);
            },
            'people' => function () use ($user): array {
                return $this->people($user);
            },
            'automations' => function () use ($user, $period): array {
                return $this->automations($user, $period);
            },
        ];

        foreach ($sources as $name => $loader) {
            $attempted++;
            try {
                $batch = $loader();
                $succeeded++;
                $freshness[$name] = 'ok';
                match ($name) {
                    'commitments' => $commitments = $batch,
                    'meetings' => $meetings = $batch,
                    'projects' => $projects = $batch,
                    'people' => $people = $batch,
                    'automations' => $automations = $batch,
                    default => null,
                };
            } catch (Throwable $exception) {
                $failed++;
                $freshness[$name] = 'failed';
                $errors[] = $name;
                Log::info('leadership review source failed', [
                    'source' => $name,
                    'error_class' => $exception::class,
                ]);
            }
        }

        $ownerIds = $this->ownerPersonIds($user, $people);
        $knownNames = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['display_name'] ?? '')),
            $people,
        )));
        $knownNames[] = trim((string) $user->name);

        return [
            'commitments' => $commitments,
            'meetings' => $meetings,
            'projects' => $projects,
            'people' => $people,
            'automation_runs' => $automations,
            'owner_person_ids' => $ownerIds,
            'owner_name' => trim((string) $user->name),
            'known_names' => array_values(array_unique(array_filter($knownNames))),
            'snapshot' => [
                'sources_attempted' => $attempted,
                'sources_succeeded' => $succeeded,
                'sources_failed' => $failed,
                'errors' => $errors,
                'freshness' => $freshness,
                'data_through' => CarbonImmutable::now('UTC')->toIso8601String(),
                'period_start' => $period->start->toIso8601String(),
                'period_end' => $period->end->toIso8601String(),
                'timezone' => $period->timezone,
                'review_type' => $type->value,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commitments(
        User $user,
        LeadershipReviewPeriod $period,
        LeadershipReviewType $type,
        ?int $projectId,
        ?int $personId,
        ?int $meetingId,
    ): array {
        if (! Schema::hasTable('commitments')) {
            return [];
        }

        $query = Commitment::query()
            ->where('user_id', $user->id)
            ->where(function ($inner) use ($period): void {
                $inner->whereBetween('created_at', [$period->start, $period->end])
                    ->orWhereBetween('detected_at', [$period->start, $period->end])
                    ->orWhereBetween('deadline_at', [$period->start, $period->end])
                    ->orWhere(function ($open) use ($period): void {
                        $open->where('created_at', '<=', $period->end)
                            ->whereIn('status', [
                                CommitmentEffectiveStatus::Detected,
                                CommitmentEffectiveStatus::Open,
                                CommitmentEffectiveStatus::DueSoon,
                                CommitmentEffectiveStatus::Overdue,
                                CommitmentEffectiveStatus::LikelyDone,
                            ]);
                    });
            });

        if ($type === LeadershipReviewType::Project && $projectId !== null) {
            $query->where('project_id', $projectId);
        }
        if ($type === LeadershipReviewType::Person && $personId !== null) {
            $query->where('person_id', $personId);
        }
        if ($type === LeadershipReviewType::Meeting && $meetingId !== null) {
            $query->where('meeting_id', $meetingId);
        }

        return $query->orderBy('id')->get()->map(function (Commitment $commitment): array {
            $status = $commitment->status instanceof CommitmentEffectiveStatus
                ? $commitment->status->value
                : (string) $commitment->status;

            return [
                'id' => (int) $commitment->id,
                'title' => $this->clip((string) $commitment->title, 180),
                'person_id' => $commitment->person_id !== null ? (int) $commitment->person_id : null,
                'person_name' => $this->clip((string) ($commitment->person_name_raw ?: ''), 80),
                'unresolved_person' => (bool) $commitment->unresolved_person,
                'project_id' => $commitment->project_id !== null ? (int) $commitment->project_id : null,
                'meeting_id' => $commitment->meeting_id !== null ? (int) $commitment->meeting_id : null,
                'deadline_at' => optional($commitment->deadline_at)?->toIso8601String(),
                'deadline_raw' => $this->clip((string) ($commitment->deadline_raw ?: ''), 80),
                'has_expected_result' => filled($commitment->expected_result),
                'status' => $status,
                'last_notified_at' => optional($commitment->last_notified_at)?->toIso8601String(),
                'created_at' => optional($commitment->created_at)?->toIso8601String(),
            ];
        })->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function meetings(
        User $user,
        LeadershipReviewPeriod $period,
        LeadershipReviewType $type,
        ?int $projectId,
        ?int $personId,
        ?int $meetingId,
    ): array {
        if (! Schema::hasTable('meetings')) {
            return [];
        }

        $query = Meeting::query()
            ->with(['currentAnalysis', 'project'])
            ->where('user_id', $user->id)
            ->where(function ($inner) use ($period): void {
                $inner->whereBetween('started_at', [$period->start, $period->end])
                    ->orWhere(function ($created) use ($period): void {
                        $created->whereNull('started_at')
                            ->whereBetween('created_at', [$period->start, $period->end]);
                    });
            });

        if ($type === LeadershipReviewType::Project && $projectId !== null) {
            $query->where('project_id', $projectId);
        }
        if ($type === LeadershipReviewType::Meeting && $meetingId !== null) {
            $query->where('id', $meetingId);
        }

        $rows = $query->orderBy('started_at')->orderBy('id')->get();

        $out = [];
        foreach ($rows as $meeting) {
            $compact = $this->compactMeeting($meeting);
            if ($type === LeadershipReviewType::Person && $personId !== null) {
                $person = Person::query()->where('user_id', $user->id)->whereKey($personId)->first();
                $name = $this->normalizeName((string) ($person?->display_name ?? ''));
                if ($name === '' || ! $this->meetingHasOwner($compact, $name)) {
                    continue;
                }
            }
            $out[] = $compact;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function compactMeeting(Meeting $meeting): array
    {
        $result = is_array($meeting->currentAnalysis?->result_json) ? $meeting->currentAnalysis->result_json : [];
        $actions = [];
        foreach (is_array($result['action_items'] ?? null) ? $result['action_items'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $actions[] = [
                'task' => $this->clip((string) ($item['task'] ?? ''), 180),
                'owner' => $this->clip((string) ($item['owner'] ?? ''), 80),
                'deadline_raw' => $this->clip((string) ($item['deadline_raw'] ?? $item['deadline'] ?? ''), 80),
                'deadline_at' => isset($item['deadline_at']) ? $this->clip((string) $item['deadline_at'], 40) : null,
            ];
        }

        $decisions = [];
        foreach (is_array($result['decisions'] ?? null) ? $result['decisions'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $decisions[] = ['text' => $this->clip((string) ($item['text'] ?? ''), 180)];
        }

        $topics = [];
        foreach (is_array($result['topics'] ?? null) ? $result['topics'] : [] as $topic) {
            if (is_string($topic) && trim($topic) !== '') {
                $topics[] = $this->clip($topic, 80);
            }
        }

        $openQuestions = 0;
        foreach (is_array($result['open_questions'] ?? null) ? $result['open_questions'] : [] as $item) {
            if (is_array($item) && trim((string) ($item['text'] ?? '')) !== '') {
                $openQuestions++;
            }
        }

        $followUps = 0;
        foreach (is_array($result['follow_ups'] ?? null) ? $result['follow_ups'] : [] as $item) {
            if (is_array($item) && trim((string) ($item['text'] ?? '')) !== '') {
                $followUps++;
            }
        }

        $identities = [];
        foreach (is_array($result['unresolved_identities'] ?? null) ? $result['unresolved_identities'] : [] as $identity) {
            if (is_string($identity) && trim($identity) !== '') {
                $identities[] = $this->clip($identity, 80);
            }
        }

        $analysisStatus = $meeting->analysis_status instanceof MeetingAnalysisStatus
            ? $meeting->analysis_status->value
            : (string) $meeting->analysis_status;

        return [
            'id' => (int) $meeting->id,
            'title' => $this->clip((string) $meeting->title, 180),
            'project_id' => $meeting->project_id !== null ? (int) $meeting->project_id : null,
            'project_name' => $this->clip((string) ($meeting->project?->name ?: ''), 80),
            'started_at' => optional($meeting->started_at)?->toIso8601String(),
            'analysis_status' => $analysisStatus,
            'analyzed' => $analysisStatus === MeetingAnalysisStatus::Completed->value && $result !== [],
            'action_items' => $actions,
            'decisions' => $decisions,
            'topics' => $topics,
            'open_questions' => $openQuestions,
            'follow_ups' => $followUps,
            'unresolved_identities' => $identities,
            'risks_count' => count(is_array($result['risks'] ?? null) ? $result['risks'] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $meeting
     */
    private function meetingHasOwner(array $meeting, string $name): bool
    {
        foreach (is_array($meeting['action_items'] ?? null) ? $meeting['action_items'] : [] as $action) {
            if (is_array($action) && $this->normalizeName((string) ($action['owner'] ?? '')) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function projects(User $user, LeadershipReviewType $type, ?int $projectId): array
    {
        if (! Schema::hasTable('projects')) {
            return [];
        }

        $query = Project::query()->where('user_id', $user->id);
        if ($type === LeadershipReviewType::Project && $projectId !== null) {
            $query->where('id', $projectId);
        }

        return $query->orderBy('id')->get(['id', 'name', 'owner_person_id'])->map(static fn (Project $project): array => [
            'id' => (int) $project->id,
            'name' => (string) $project->name,
            'owner_person_id' => $project->owner_person_id !== null ? (int) $project->owner_person_id : null,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function people(User $user): array
    {
        if (! Schema::hasTable('people')) {
            return [];
        }

        return Person::query()->where('user_id', $user->id)->orderBy('id')->get([
            'id',
            'display_name',
            'primary_email',
            'normalized_name',
        ])->map(static fn (Person $person): array => [
            'id' => (int) $person->id,
            'display_name' => (string) $person->display_name,
            'primary_email' => (string) ($person->primary_email ?: ''),
            'normalized_name' => (string) ($person->normalized_name ?: ''),
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function automations(User $user, LeadershipReviewPeriod $period): array
    {
        if (! Schema::hasTable('automation_runs')) {
            return [];
        }

        return AutomationRun::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$period->start, $period->end])
            ->whereIn('status', [AutomationRunOutcome::Failed, AutomationRunOutcome::Retryable])
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'automation_type', 'status'])
            ->map(static function (AutomationRun $run): array {
                $type = $run->automation_type instanceof AutomationType
                    ? $run->automation_type->value
                    : (string) $run->automation_type;
                $status = $run->status instanceof AutomationRunOutcome
                    ? $run->status->value
                    : (string) $run->status;

                return [
                    'id' => (int) $run->id,
                    'automation_type' => $type,
                    'status' => $status,
                ];
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $people
     * @return list<int>
     */
    private function ownerPersonIds(User $user, array $people): array
    {
        $ids = [];
        $email = mb_strtolower(trim((string) $user->email));
        $name = $this->normalizeName((string) $user->name);

        foreach ($people as $person) {
            $personEmail = mb_strtolower(trim((string) ($person['primary_email'] ?? '')));
            $personName = $this->normalizeName((string) ($person['display_name'] ?? $person['normalized_name'] ?? ''));
            if (($email !== '' && $personEmail === $email) || ($name !== '' && $personName === $name)) {
                $ids[] = (int) $person['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function normalizeName(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
