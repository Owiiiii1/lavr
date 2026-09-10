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
use App\Models\User;
use App\Services\Commitments\DTO\CommitmentCandidate;
use App\Services\Meetings\ParticipantResolver;
use App\Services\Projects\ProjectNameNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class CommitmentPromotionService
{
    public function __construct(
        private readonly CommitmentEvidenceService $evidence,
        private readonly CommitmentStatusService $statuses,
        private readonly ParticipantResolver $participants,
    ) {}

    /**
     * @return array{created: list<Commitment>, updated: list<Commitment>, skipped: list<array<string, mixed>>}
     */
    public function promoteFromMeetingAnalysis(User $user, Meeting $meeting, MeetingAnalysis $analysis, bool $auto = true): array
    {
        $result = is_array($analysis->result_json) ? $analysis->result_json : [];
        $items = is_array($result['commitments_detected'] ?? null) ? $result['commitments_detected'] : [];
        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $candidate = $this->candidateFromMeetingItem($user, $meeting, $analysis, $item, (int) $index);

            if ($candidate === null) {
                $skipped[] = ['index' => (int) $index, 'reason' => 'invalid_item'];

                continue;
            }

            if ($auto && ! $this->shouldAutoCreate($candidate)) {
                $skipped[] = [
                    'index' => (int) $index,
                    'reason' => $this->autoSkipReason($candidate),
                    'action' => $candidate->action,
                    'confidence' => $candidate->confidence->value,
                ];

                continue;
            }

            $outcome = $this->promote($candidate);

            if ($outcome['created']) {
                $created[] = $outcome['commitment'];
            } else {
                $updated[] = $outcome['commitment'];
            }
        }

        Log::info('commitment meeting promotion', [
            'meeting_id' => $meeting->id,
            'analysis_id' => $analysis->id,
            'created' => count($created),
            'updated' => count($updated),
            'skipped' => count($skipped),
            'auto' => $auto,
        ]);

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @return array{commitment: Commitment, created: bool}
     */
    public function promote(CommitmentCandidate $candidate): array
    {
        $existing = Commitment::query()
            ->where('user_id', $candidate->user->id)
            ->where('fingerprint', $candidate->fingerprint)
            ->whereNull('merged_into_id')
            ->first();

        if ($existing !== null) {
            $this->appendSourceEvidence($existing, $candidate);

            if ($existing->lifecycle_status === CommitmentLifecycleStatus::Detected
                && $existing->owner_edited_at === null) {
                $this->refreshDetectedFields($existing, $candidate);
            }

            return ['commitment' => $existing->fresh() ?? $existing, 'created' => false];
        }

        $manual = $candidate->sourceType === CommitmentSourceType::Manual;
        $lifecycle = $manual ? CommitmentLifecycleStatus::Open : CommitmentLifecycleStatus::Detected;

        $commitment = Commitment::query()->create([
            'user_id' => $candidate->user->id,
            'person_id' => $candidate->personId,
            'person_name_raw' => $candidate->personNameRaw,
            'unresolved_person' => $candidate->unresolvedPerson,
            'project_id' => $candidate->projectId,
            'meeting_id' => $candidate->meetingId,
            'organization_id' => $candidate->organizationId,
            'meeting_analysis_id' => $candidate->meetingAnalysisId,
            'title' => $candidate->title,
            'expected_result' => $candidate->expectedResult,
            'description' => $candidate->action,
            'deadline_raw' => $candidate->deadlineRaw,
            'deadline_at' => $candidate->deadlineAt,
            'deadline_precision' => $candidate->deadlinePrecision,
            'status' => $manual ? CommitmentEffectiveStatus::Open : CommitmentEffectiveStatus::Detected,
            'lifecycle_status' => $lifecycle,
            'confidence' => $candidate->confidence,
            'source_type' => $candidate->sourceType,
            'source_id' => $candidate->sourceId,
            'source_reference' => $candidate->sourceReference,
            'fingerprint' => $candidate->fingerprint,
            'detected_at' => now(),
            'confirmed_at' => $manual ? now() : null,
            'metadata' => $candidate->metadata === [] ? null : $candidate->metadata,
        ]);

        $this->statuses->recordHistory(
            $commitment,
            null,
            $lifecycle->value,
            $manual ? 'manual_create' : 'detected',
            $candidate->user,
        );
        $this->statuses->persist($commitment, $candidate->user, 'create');
        $this->appendSourceEvidence($commitment, $candidate);

        Log::info('commitment created', [
            'commitment_id' => $commitment->id,
            'person_id' => $commitment->person_id,
            'source_type' => $candidate->sourceType->value,
            'source_id' => $candidate->sourceId,
            'outcome' => $lifecycle->value,
        ]);

        return ['commitment' => $commitment->fresh() ?? $commitment, 'created' => true];
    }

    public function shouldAutoCreate(CommitmentCandidate $candidate): bool
    {
        if ($candidate->confidence !== CommitmentConfidence::High) {
            return false;
        }

        return mb_strlen(ProjectNameNormalizer::normalize($candidate->action)) >= 4;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    public function candidateFromMeetingItem(
        User $user,
        Meeting $meeting,
        MeetingAnalysis $analysis,
        array $item,
        int $index,
    ): ?CommitmentCandidate {
        $action = $this->nullableString($item['action'] ?? null);

        if ($action === null) {
            return null;
        }

        $personName = $this->nullableString($item['person_name'] ?? $item['person_ref'] ?? null);
        $evidence = is_array($item['evidence'] ?? null) ? $item['evidence'] : [];
        $speaker = $this->nullableString($evidence['speaker'] ?? $personName);
        $personId = $this->resolvePersonId($user, $meeting, $item, $personName ?? $speaker);
        $expected = $this->nullableString($item['expected_result'] ?? null);
        $deadlineRaw = $this->nullableString($item['deadline_raw'] ?? $item['deadline'] ?? null);
        $deadlineAt = $this->deadlineAt($item['deadline_at'] ?? null);
        $precision = $this->precision($deadlineRaw, $deadlineAt, $item['deadline_precision'] ?? null);
        $excerpt = $this->nullableString($evidence['excerpt'] ?? null);
        $confidence = CommitmentConfidence::fromLoose($item['confidence'] ?? 'medium');
        $fingerprint = CommitmentFingerprint::make(
            $user->id,
            'meeting:'.$meeting->id,
            $personId,
            $personName,
            $action,
            $expected,
            $deadlineRaw,
        );

        return new CommitmentCandidate(
            user: $user,
            action: $action,
            title: $this->titleFromAction($action),
            expectedResult: $expected,
            deadlineRaw: $deadlineRaw,
            deadlineAt: $deadlineAt,
            deadlinePrecision: $precision,
            confidence: $confidence,
            sourceType: CommitmentSourceType::Meeting,
            sourceId: $meeting->id,
            sourceReference: [
                'meeting_id' => $meeting->id,
                'meeting_analysis_id' => $analysis->id,
                'item_index' => $index,
                'speaker' => $speaker,
                'excerpt' => $excerpt,
            ],
            personId: $personId,
            personNameRaw: $personName,
            unresolvedPerson: $personId === null,
            projectId: $meeting->project_id,
            meetingId: $meeting->id,
            organizationId: $meeting->organization_id,
            meetingAnalysisId: $analysis->id,
            excerpt: $excerpt,
            speaker: $speaker,
            observedAt: $meeting->started_at?->toImmutable() ?? CarbonImmutable::now(),
            fingerprint: $fingerprint,
            metadata: ['item_index' => $index],
        );
    }

    public function titleFromAction(string $action): string
    {
        $title = trim($action);
        $limit = CommitmentConfig::titleMax();

        if (mb_strlen($title) <= $limit) {
            return $title;
        }

        return rtrim(mb_substr($title, 0, $limit - 1)).'…';
    }

    private function autoSkipReason(CommitmentCandidate $candidate): string
    {
        if ($candidate->confidence !== CommitmentConfidence::High) {
            return 'low_confidence';
        }

        return 'weak_action';
    }

    private function appendSourceEvidence(Commitment $commitment, CommitmentCandidate $candidate): void
    {
        $this->evidence->add(
            $commitment,
            CommitmentEvidenceType::Promise,
            $candidate->sourceType,
            $candidate->sourceId,
            $candidate->excerpt,
            $candidate->confidence,
            $candidate->observedAt,
            [
                'speaker' => $candidate->speaker,
                'meeting_analysis_id' => $candidate->meetingAnalysisId,
                'item_index' => $candidate->metadata['item_index'] ?? null,
            ],
        );

        if ($candidate->deadlineRaw !== null || $candidate->deadlineAt !== null) {
            $this->evidence->add(
                $commitment,
                CommitmentEvidenceType::Deadline,
                $candidate->sourceType,
                $candidate->sourceId,
                $candidate->deadlineRaw,
                $candidate->confidence,
                $candidate->observedAt,
                ['deadline_at' => $candidate->deadlineAt?->toIso8601String()],
            );
        }

        $reference = is_array($commitment->source_reference) ? $commitment->source_reference : [];
        $history = is_array($reference['analysis_ids'] ?? null) ? $reference['analysis_ids'] : [];
        if ($candidate->meetingAnalysisId !== null && ! in_array($candidate->meetingAnalysisId, $history, true)) {
            $history[] = $candidate->meetingAnalysisId;
        }
        $reference['analysis_ids'] = $history;
        $reference['meeting_analysis_id'] = $candidate->meetingAnalysisId ?? ($reference['meeting_analysis_id'] ?? null);
        $commitment->forceFill([
            'meeting_analysis_id' => $candidate->meetingAnalysisId ?? $commitment->meeting_analysis_id,
            'source_reference' => $reference,
        ])->save();
    }

    private function refreshDetectedFields(Commitment $commitment, CommitmentCandidate $candidate): void
    {
        $commitment->forceFill([
            'title' => $candidate->title,
            'expected_result' => $candidate->expectedResult ?? $commitment->expected_result,
            'description' => $candidate->action,
            'deadline_raw' => $candidate->deadlineRaw ?? $commitment->deadline_raw,
            'deadline_at' => $candidate->deadlineAt ?? $commitment->deadline_at,
            'deadline_precision' => $candidate->deadlinePrecision,
            'person_id' => $commitment->person_id ?? $candidate->personId,
            'person_name_raw' => $candidate->personNameRaw ?? $commitment->person_name_raw,
            'unresolved_person' => $commitment->person_id === null && $candidate->personId === null,
            'confidence' => $candidate->confidence,
        ])->save();

        $this->statuses->persist($commitment, $candidate->user, 'reanalysis_refresh');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolvePersonId(User $user, Meeting $meeting, array $item, ?string $name): ?int
    {
        $explicit = isset($item['person_id']) ? (int) $item['person_id'] : 0;

        if ($explicit > 0) {
            return $explicit;
        }

        $email = $this->nullableString($item['person_email'] ?? null);

        return $this->participants->resolvePersonId($user, (string) $name, $email);
    }

    private function deadlineAt(mixed $value): ?CarbonImmutable
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

    private function precision(?string $raw, ?CarbonImmutable $at, mixed $explicit): CommitmentDeadlinePrecision
    {
        if (is_string($explicit) && CommitmentDeadlinePrecision::tryFrom(mb_strtolower(trim($explicit))) !== null) {
            return CommitmentDeadlinePrecision::fromLoose($explicit);
        }

        if ($at === null && $raw === null) {
            return CommitmentDeadlinePrecision::Unknown;
        }

        if ($at === null && $raw !== null) {
            return CommitmentDeadlinePrecision::Relative;
        }

        if ($at !== null && ($at->hour !== 0 || $at->minute !== 0 || $at->second !== 0)) {
            return CommitmentDeadlinePrecision::ExactDatetime;
        }

        return CommitmentDeadlinePrecision::Date;
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
