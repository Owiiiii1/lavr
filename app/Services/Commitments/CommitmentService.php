<?php

namespace App\Services\Commitments;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentDeadlinePrecision;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Services\Commitments\DTO\CommitmentCandidate;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\Users\UserCapability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CommitmentService
{
    public function __construct(
        private readonly CommitmentStatusService $statuses,
        private readonly CommitmentEvidenceService $evidence,
        private readonly CommitmentPromotionService $promotion,
        private readonly CommitmentNotifier $notifier,
    ) {}

    public function hasFirstClass(User $user): bool
    {
        return Commitment::query()->where('user_id', $user->id)->exists();
    }

    /**
     * @return Collection<int, Commitment>
     */
    public function list(
        User $user,
        ?string $query = null,
        ?string $status = null,
        ?int $personId = null,
        ?int $projectId = null,
        ?string $sourceType = null,
        bool $overdueOnly = false,
    ): Collection {
        $this->assertCanManage($user);

        $builder = Commitment::query()
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->with(['person:id,display_name', 'project:id,name', 'meeting:id,title'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due_soon' THEN 1 WHEN 'detected' THEN 2 WHEN 'open' THEN 3 WHEN 'likely_done' THEN 4 ELSE 5 END")
            ->orderBy('deadline_at')
            ->orderByDesc('id');

        if (is_string($query) && trim($query) !== '') {
            $term = '%'.trim($query).'%';
            $builder->where(function ($inner) use ($term): void {
                $inner->where('title', 'like', $term)
                    ->orWhere('expected_result', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhere('person_name_raw', 'like', $term)
                    ->orWhereHas('person', fn ($person) => $person->where('display_name', 'like', $term))
                    ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $term));
            });
        }

        if ($overdueOnly) {
            $builder->where('status', CommitmentEffectiveStatus::Overdue);
        } elseif (is_string($status) && $status !== '') {
            $effective = CommitmentEffectiveStatus::tryFromLoose($status);
            if ($effective !== null) {
                $builder->where('status', $effective);
            }
        }

        if ($personId !== null && $personId > 0) {
            $builder->where('person_id', $personId);
        }

        if ($projectId !== null && $projectId > 0) {
            $builder->where('project_id', $projectId);
        }

        if (is_string($sourceType) && $sourceType !== '') {
            $source = CommitmentSourceType::tryFrom($sourceType);
            if ($source !== null) {
                $builder->where('source_type', $source);
            }
        }

        return $builder->limit(200)->get();
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    public function workspaceSections(User $user): array
    {
        $items = $this->list($user);

        $bucket = [
            'overdue' => [],
            'due_soon' => [],
            'open' => [],
            'detected' => [],
            'likely_done' => [],
            'confirmed' => [],
        ];

        foreach ($items as $commitment) {
            $status = $commitment->status instanceof CommitmentEffectiveStatus
                ? $commitment->status->value
                : (string) $commitment->status;

            if (! array_key_exists($status, $bucket)) {
                continue;
            }

            if ($status === 'confirmed' && count($bucket['confirmed']) >= CommitmentConfig::confirmedRecentLimit()) {
                continue;
            }

            $bucket[$status][] = $this->serializeSummary($commitment);
        }

        return $bucket;
    }

    /**
     * @return list<Commitment>
     */
    public function attentionForToday(User $user): array
    {
        $this->assertCanManage($user);
        $timezone = (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now($timezone);
        $start = $now->startOfDay()->utc();
        $end = $now->endOfDay()->utc();

        return Commitment::query()
            ->where('user_id', $user->id)
            ->whereNull('merged_into_id')
            ->whereIn('status', [
                CommitmentEffectiveStatus::Overdue,
                CommitmentEffectiveStatus::DueSoon,
                CommitmentEffectiveStatus::Open,
            ])
            ->where(function ($query) use ($start, $end): void {
                $query->where('status', CommitmentEffectiveStatus::Overdue)
                    ->orWhere('status', CommitmentEffectiveStatus::DueSoon)
                    ->orWhere(function ($inner) use ($start, $end): void {
                        $inner->where('status', CommitmentEffectiveStatus::Open)
                            ->whereNotNull('deadline_at')
                            ->whereBetween('deadline_at', [$start, $end]);
                    });
            })
            ->with(['person:id,display_name', 'project:id,name'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due_soon' THEN 1 ELSE 2 END")
            ->orderBy('deadline_at')
            ->limit(12)
            ->get()
            ->all();
    }

    /**
     * @return Collection<int, Commitment>
     */
    public function forPerson(User $user, Person $person): Collection
    {
        $this->ownedPerson($user, $person);

        return Commitment::query()
            ->where('user_id', $user->id)
            ->where('person_id', $person->id)
            ->whereNull('merged_into_id')
            ->with(['project:id,name'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due_soon' THEN 1 WHEN 'open' THEN 2 WHEN 'detected' THEN 3 WHEN 'likely_done' THEN 4 ELSE 5 END")
            ->orderByDesc('completed_at')
            ->orderBy('deadline_at')
            ->limit(40)
            ->get();
    }

    /**
     * @return Collection<int, Commitment>
     */
    public function forProject(User $user, Project $project): Collection
    {
        if ((int) $project->user_id !== (int) $user->id) {
            throw new CommitmentException('not_found', 'Project not found.');
        }

        return Commitment::query()
            ->where('user_id', $user->id)
            ->where('project_id', $project->id)
            ->whereNull('merged_into_id')
            ->with(['person:id,display_name'])
            ->orderByRaw("CASE status WHEN 'overdue' THEN 0 WHEN 'due_soon' THEN 1 WHEN 'open' THEN 2 WHEN 'detected' THEN 3 WHEN 'likely_done' THEN 4 ELSE 5 END")
            ->orderByDesc('completed_at')
            ->orderBy('deadline_at')
            ->limit(40)
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function meetingItems(User $user, Meeting $meeting): array
    {
        $this->assertCanManage($user);
        $analysis = $meeting->currentAnalysis ?? $meeting->analyses()->orderByDesc('version')->first();
        $result = is_array($analysis?->result_json) ? $analysis->result_json : [];
        $detected = is_array($result['commitments_detected'] ?? null) ? $result['commitments_detected'] : [];
        $promoted = Commitment::query()
            ->where('user_id', $user->id)
            ->where('meeting_id', $meeting->id)
            ->whereNull('merged_into_id')
            ->get()
            ->keyBy(function (Commitment $commitment): string {
                $reference = is_array($commitment->source_reference) ? $commitment->source_reference : [];

                return isset($reference['item_index']) ? 'idx:'.(int) $reference['item_index'] : 'fp:'.$commitment->fingerprint;
            });

        $items = [];

        foreach ($detected as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = 'idx:'.(int) $index;
            $match = $promoted->get($key);
            if ($match === null && $analysis instanceof MeetingAnalysis) {
                $candidate = $this->promotion->candidateFromMeetingItem($user, $meeting, $analysis, $item, (int) $index);
                if ($candidate !== null) {
                    $match = $promoted->firstWhere('fingerprint', $candidate->fingerprint);
                }
            }

            $items[] = [
                'index' => (int) $index,
                'action' => $item['action'] ?? null,
                'person_name' => $item['person_name'] ?? $item['person_ref'] ?? null,
                'expected_result' => $item['expected_result'] ?? null,
                'deadline_raw' => $item['deadline_raw'] ?? null,
                'deadline_at' => $item['deadline_at'] ?? null,
                'confidence' => $item['confidence'] ?? 'medium',
                'promoted' => $match !== null,
                'commitment_id' => $match?->id,
                'status' => $match?->status instanceof CommitmentEffectiveStatus ? $match->status->value : $match?->status,
            ];
        }

        return $items;
    }

    public function owned(User $user, Commitment $commitment): Commitment
    {
        $this->assertCanManage($user);

        if ((int) $commitment->user_id !== (int) $user->id) {
            throw new CommitmentException('not_found', 'Commitment not found.');
        }

        return $commitment;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function createManual(User $user, array $input): Commitment
    {
        $this->assertCanManage($user);
        $action = trim((string) ($input['title'] ?? $input['action'] ?? ''));

        if ($action === '') {
            throw new CommitmentException('invalid_action', 'Action is required.');
        }

        $personId = isset($input['person_id']) ? (int) $input['person_id'] : 0;
        $person = $personId > 0 ? Person::query()->where('user_id', $user->id)->whereKey($personId)->first() : null;

        if ($personId > 0 && $person === null) {
            throw new CommitmentException('invalid_person', 'Person not found.');
        }

        $projectId = $this->ownedOptionalId($user, Project::class, isset($input['project_id']) ? (int) $input['project_id'] : 0);
        $deadlineRaw = $this->nullableString($input['deadline_raw'] ?? $input['deadline'] ?? null);
        $deadlineAt = $this->parseDeadline($input['deadline_at'] ?? null);
        $candidate = new CommitmentCandidate(
            user: $user,
            action: $action,
            title: $this->promotion->titleFromAction($action),
            expectedResult: $this->nullableString($input['expected_result'] ?? null),
            deadlineRaw: $deadlineRaw,
            deadlineAt: $deadlineAt,
            deadlinePrecision: CommitmentDeadlinePrecision::fromLoose($input['deadline_precision'] ?? ($deadlineAt ? 'date' : 'unknown')),
            confidence: CommitmentConfidence::High,
            sourceType: CommitmentSourceType::Manual,
            sourceId: null,
            sourceReference: ['source' => 'manual'],
            personId: $person?->id,
            personNameRaw: $person?->display_name,
            unresolvedPerson: $person === null,
            projectId: $projectId,
            meetingId: null,
            organizationId: $this->ownedOptionalId($user, Organization::class, isset($input['organization_id']) ? (int) $input['organization_id'] : 0),
            meetingAnalysisId: null,
            excerpt: $this->nullableString($input['notes'] ?? $input['description'] ?? null),
            speaker: null,
            observedAt: CarbonImmutable::now(),
            fingerprint: CommitmentFingerprint::uniqueManual(),
            metadata: ['created' => 'manual'],
        );

        $created = $this->promotion->promote($candidate)['commitment'];

        if ($candidate->excerpt !== null) {
            $this->evidence->add(
                $created,
                CommitmentEvidenceType::Other,
                CommitmentSourceType::Manual,
                null,
                $candidate->excerpt,
                CommitmentConfidence::High,
            );
        }

        $this->notifier->notifyOpened($created);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, Commitment $commitment, array $input): Commitment
    {
        $commitment = $this->owned($user, $commitment);
        $fields = [];

        if (array_key_exists('title', $input) || array_key_exists('action', $input)) {
            $title = trim((string) ($input['title'] ?? $input['action'] ?? $commitment->title));
            if ($title === '') {
                throw new CommitmentException('invalid_action', 'Action is required.');
            }
            $fields['title'] = $this->promotion->titleFromAction($title);
            $fields['description'] = $title;
        }

        if (array_key_exists('expected_result', $input)) {
            $fields['expected_result'] = $this->nullableString($input['expected_result']);
        }

        if (array_key_exists('description', $input)) {
            $fields['description'] = $this->nullableString($input['description']);
        }

        if (array_key_exists('person_id', $input)) {
            $personId = (int) $input['person_id'];
            $person = $personId > 0 ? Person::query()->where('user_id', $user->id)->whereKey($personId)->first() : null;
            if ($personId > 0 && $person === null) {
                throw new CommitmentException('invalid_person', 'Person not found.');
            }
            $fields['person_id'] = $person?->id;
            $fields['person_name_raw'] = $person?->display_name ?? $commitment->person_name_raw;
            $fields['unresolved_person'] = $person === null;
        }

        if (array_key_exists('project_id', $input)) {
            $fields['project_id'] = $this->ownedOptionalId($user, Project::class, (int) $input['project_id']);
        }

        if (array_key_exists('deadline_at', $input) || array_key_exists('deadline_raw', $input)) {
            $fields['deadline_raw'] = $this->nullableString($input['deadline_raw'] ?? $input['deadline'] ?? $commitment->deadline_raw);
            $fields['deadline_at'] = array_key_exists('deadline_at', $input)
                ? $this->parseDeadline($input['deadline_at'])
                : $commitment->deadline_at;
            $fields['deadline_precision'] = CommitmentDeadlinePrecision::fromLoose($input['deadline_precision'] ?? 'date');
        }

        if (array_key_exists('notes', $input) && $this->nullableString($input['notes']) !== null) {
            $this->evidence->add(
                $commitment,
                CommitmentEvidenceType::Other,
                CommitmentSourceType::Manual,
                null,
                (string) $input['notes'],
                CommitmentConfidence::High,
            );
        }

        $fields['owner_edited_at'] = now();
        $commitment->forceFill($fields)->save();
        $this->statuses->persist($commitment, $user, 'owner_edit');

        return $commitment->fresh(['person', 'project', 'meeting', 'evidence', 'statusHistory']) ?? $commitment;
    }

    public function confirmDetected(User $user, Commitment $commitment, array $input = []): Commitment
    {
        $commitment = $this->owned($user, $commitment);

        if ($commitment->lifecycle_status !== CommitmentLifecycleStatus::Detected) {
            throw new CommitmentException('invalid_status', 'Only detected commitments can be confirmed.');
        }

        if ($input !== []) {
            $commitment = $this->update($user, $commitment, $input);
        }

        $updated = $this->statuses->setLifecycle($commitment, CommitmentLifecycleStatus::Open, $user, 'confirm_detected');
        $this->notifier->notifyOpened($updated);

        return $updated;
    }

    public function dismiss(User $user, Commitment $commitment): Commitment
    {
        $commitment = $this->owned($user, $commitment);
        $commitment->cancel_reason = 'not_a_commitment';

        return $this->statuses->setLifecycle($commitment, CommitmentLifecycleStatus::Discarded, $user, 'not_a_commitment');
    }

    public function cancel(User $user, Commitment $commitment, ?string $reason = null): Commitment
    {
        $commitment = $this->owned($user, $commitment);
        $commitment->cancel_reason = $reason ?: 'cancelled';

        return $this->statuses->setLifecycle($commitment, CommitmentLifecycleStatus::Cancelled, $user, 'cancelled');
    }

    public function markLikelyDone(User $user, Commitment $commitment, ?string $note = null): Commitment
    {
        $commitment = $this->owned($user, $commitment);

        if ($commitment->lifecycle_status !== CommitmentLifecycleStatus::Open
            && $commitment->lifecycle_status !== CommitmentLifecycleStatus::LikelyDone) {
            throw new CommitmentException('invalid_status', 'Confirm the commitment before marking it likely done.');
        }

        if ($note !== null && trim($note) !== '') {
            $this->evidence->add(
                $commitment,
                CommitmentEvidenceType::Completion,
                CommitmentSourceType::Manual,
                null,
                $note,
                CommitmentConfidence::High,
            );
        } elseif (! $this->evidence->hasCompletionSignal($commitment)) {
            throw new CommitmentException('missing_completion_evidence', 'Add completion evidence first.');
        }

        $updated = $this->statuses->setLifecycle($commitment, CommitmentLifecycleStatus::LikelyDone, $user, 'likely_done');
        $this->notifier->notifyLikelyDone($updated);

        return $updated;
    }

    public function markConfirmed(User $user, Commitment $commitment, ?string $note = null): Commitment
    {
        $commitment = $this->owned($user, $commitment);

        if ($note !== null && trim($note) !== '') {
            $commitment->completion_note = trim($note);
            $this->evidence->add(
                $commitment,
                CommitmentEvidenceType::Confirmation,
                CommitmentSourceType::Manual,
                null,
                $note,
                CommitmentConfidence::High,
            );
        }

        return $this->statuses->setLifecycle($commitment, CommitmentLifecycleStatus::Confirmed, $user, 'owner_confirmed');
    }

    public function addEvidence(
        User $user,
        Commitment $commitment,
        string $type,
        ?string $excerpt,
        ?string $sourceType = null,
    ): Commitment {
        $commitment = $this->owned($user, $commitment);
        $evidenceType = CommitmentEvidenceType::tryFrom($type) ?? CommitmentEvidenceType::Other;
        $source = CommitmentSourceType::tryFrom((string) $sourceType) ?? CommitmentSourceType::Manual;

        $this->evidence->add(
            $commitment,
            $evidenceType,
            $source,
            $source === CommitmentSourceType::Manual ? null : $commitment->source_id,
            $excerpt,
            CommitmentConfidence::High,
        );

        if (in_array($evidenceType, [CommitmentEvidenceType::Delivery, CommitmentEvidenceType::Completion], true)
            && $commitment->lifecycle_status === CommitmentLifecycleStatus::Open) {
            return $this->markLikelyDone($user, $commitment, $excerpt);
        }

        return $commitment->fresh(['evidence']) ?? $commitment;
    }

    public function merge(User $user, Commitment $target, Commitment $duplicate): Commitment
    {
        $target = $this->owned($user, $target);
        $duplicate = $this->owned($user, $duplicate);

        if ($target->id === $duplicate->id) {
            throw new CommitmentException('invalid_merge', 'Cannot merge a commitment into itself.');
        }

        return DB::transaction(function () use ($user, $target, $duplicate): Commitment {
            foreach ($duplicate->evidence as $row) {
                $this->evidence->add(
                    $target,
                    $row->evidence_type instanceof CommitmentEvidenceType ? $row->evidence_type : CommitmentEvidenceType::Other,
                    $row->source_type instanceof CommitmentSourceType ? $row->source_type : CommitmentSourceType::Other,
                    $row->source_id,
                    $row->excerpt,
                    $row->confidence instanceof CommitmentConfidence ? $row->confidence : CommitmentConfidence::Medium,
                    $row->observed_at?->toImmutable(),
                    is_array($row->metadata) ? $row->metadata : [],
                );
            }

            if ($target->deadline_at === null || ($duplicate->deadline_at !== null && $duplicate->deadline_at->lessThan($target->deadline_at))) {
                $target->deadline_at = $duplicate->deadline_at;
                $target->deadline_raw = $duplicate->deadline_raw ?? $target->deadline_raw;
                $target->deadline_precision = $duplicate->deadline_precision ?? $target->deadline_precision;
            }

            if ($target->person_id === null && $duplicate->person_id !== null) {
                $target->person_id = $duplicate->person_id;
                $target->unresolved_person = false;
                $target->person_name_raw = $duplicate->person_name_raw;
            }

            $target->save();
            $duplicate->merged_into_id = $target->id;
            $duplicate->cancel_reason = 'merged';
            $this->statuses->setLifecycle($duplicate, CommitmentLifecycleStatus::Cancelled, $user, 'merged');
            $this->statuses->recordHistory($target, $target->status?->value, $target->status?->value, 'merged_from', $user, [
                'merged_from' => $duplicate->id,
            ]);
            $this->statuses->persist($target, $user, 'merge');

            Log::info('commitment merged', [
                'commitment_id' => $target->id,
                'person_id' => $target->person_id,
                'source_type' => $target->source_type instanceof CommitmentSourceType ? $target->source_type->value : (string) $target->source_type,
                'source_id' => $duplicate->id,
                'outcome' => 'merged',
            ]);

            return $target->fresh(['evidence', 'person', 'project']) ?? $target;
        });
    }

    public function promoteMeetingItem(User $user, Meeting $meeting, int $index): Commitment
    {
        $this->assertCanManage($user);

        if ((int) $meeting->user_id !== (int) $user->id) {
            throw new CommitmentException('not_found', 'Meeting not found.');
        }

        $analysis = $meeting->currentAnalysis ?? $meeting->analyses()->orderByDesc('version')->first();

        if (! $analysis instanceof MeetingAnalysis) {
            throw new CommitmentException('missing_analysis', 'Meeting has no analysis.');
        }

        $result = is_array($analysis->result_json) ? $analysis->result_json : [];
        $items = is_array($result['commitments_detected'] ?? null) ? $result['commitments_detected'] : [];
        $item = $items[$index] ?? null;

        if (! is_array($item)) {
            throw new CommitmentException('invalid_item', 'Commitment suggestion not found.');
        }

        $candidate = $this->promotion->candidateFromMeetingItem($user, $meeting, $analysis, $item, $index);

        if ($candidate === null) {
            throw new CommitmentException('invalid_item', 'Commitment suggestion is incomplete.');
        }

        return $this->promotion->promote($candidate)['commitment'];
    }

    public function refreshStatuses(?int $limit = 500): int
    {
        $changed = 0;
        $rows = Commitment::query()
            ->whereNull('merged_into_id')
            ->where('lifecycle_status', CommitmentLifecycleStatus::Open)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($rows as $commitment) {
            $before = $commitment->status instanceof CommitmentEffectiveStatus
                ? $commitment->status
                : CommitmentEffectiveStatus::tryFromLoose((string) $commitment->status);
            $after = $this->statuses->persist($commitment, null, 'deadline_policy');

            if ($before !== $after) {
                $this->notifier->notifyStatusTransition($commitment->fresh() ?? $commitment, $after);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSummary(Commitment $commitment): array
    {
        $commitment->loadMissing(['person:id,display_name', 'project:id,name']);

        return [
            'id' => $commitment->id,
            'title' => $commitment->title,
            'expected_result' => $commitment->expected_result,
            'status' => $commitment->status instanceof CommitmentEffectiveStatus ? $commitment->status->value : (string) $commitment->status,
            'lifecycle_status' => $commitment->lifecycle_status instanceof CommitmentLifecycleStatus ? $commitment->lifecycle_status->value : (string) $commitment->lifecycle_status,
            'confidence' => $commitment->confidence instanceof CommitmentConfidence ? $commitment->confidence->value : (string) $commitment->confidence,
            'deadline_raw' => $commitment->deadline_raw,
            'deadline_at' => $commitment->deadline_at?->toIso8601String(),
            'source_type' => $commitment->source_type instanceof CommitmentSourceType ? $commitment->source_type->value : (string) $commitment->source_type,
            'unresolved_person' => (bool) $commitment->unresolved_person,
            'person' => $commitment->person ? [
                'id' => $commitment->person->id,
                'display_name' => $commitment->person->display_name,
            ] : null,
            'person_name_raw' => $commitment->person_name_raw,
            'project' => $commitment->project ? [
                'id' => $commitment->project->id,
                'name' => $commitment->project->name,
            ] : null,
            'href' => '/lavr/commitments/'.$commitment->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Commitment $commitment): array
    {
        $commitment->loadMissing([
            'person:id,display_name',
            'project:id,name',
            'meeting:id,title',
            'organization:id,name',
            'evidence',
            'statusHistory',
        ]);

        return [
            ...$this->serializeSummary($commitment),
            'description' => $commitment->description,
            'deadline_precision' => $commitment->deadline_precision instanceof CommitmentDeadlinePrecision
                ? $commitment->deadline_precision->value
                : $commitment->deadline_precision,
            'source_id' => $commitment->source_id,
            'source_reference' => $commitment->source_reference,
            'meeting' => $commitment->meeting ? [
                'id' => $commitment->meeting->id,
                'title' => $commitment->meeting->title,
            ] : null,
            'organization' => $commitment->organization ? [
                'id' => $commitment->organization->id,
                'name' => $commitment->organization->name,
            ] : null,
            'completion_note' => $commitment->completion_note,
            'cancel_reason' => $commitment->cancel_reason,
            'detected_at' => $commitment->detected_at?->toIso8601String(),
            'confirmed_at' => $commitment->confirmed_at?->toIso8601String(),
            'completed_at' => $commitment->completed_at?->toIso8601String(),
            'cancelled_at' => $commitment->cancelled_at?->toIso8601String(),
            'evidence' => $commitment->evidence->sortBy('id')->values()->map(fn ($row): array => [
                'id' => $row->id,
                'evidence_type' => $row->evidence_type instanceof CommitmentEvidenceType ? $row->evidence_type->value : (string) $row->evidence_type,
                'source_type' => $row->source_type instanceof CommitmentSourceType ? $row->source_type->value : (string) $row->source_type,
                'excerpt' => $row->excerpt,
                'observed_at' => $row->observed_at?->toIso8601String(),
                'confidence' => $row->confidence instanceof CommitmentConfidence ? $row->confidence->value : (string) $row->confidence,
            ])->all(),
            'history' => $commitment->statusHistory->sortBy('id')->values()->map(fn ($row): array => [
                'id' => $row->id,
                'from_status' => $row->from_status,
                'to_status' => $row->to_status,
                'reason' => $row->reason,
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    private function assertCanManage(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::COMMITMENTS)) {
            throw new CommitmentException('forbidden', 'Commitments are not available.');
        }
    }

    private function ownedPerson(User $user, Person $person): void
    {
        if ((int) $person->user_id !== (int) $user->id) {
            throw new CommitmentException('not_found', 'Person not found.');
        }
    }

    private function ownedOptionalId(User $user, string $model, int $id): ?int
    {
        if ($id < 1) {
            return null;
        }

        $row = $model::query()->where('user_id', $user->id)->whereKey($id)->first();

        if ($row === null) {
            throw new CommitmentException('not_found', 'Related record not found.');
        }

        return (int) $row->id;
    }

    private function parseDeadline(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
