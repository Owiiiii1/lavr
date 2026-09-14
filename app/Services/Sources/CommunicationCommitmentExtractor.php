<?php

namespace App\Services\Sources;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentDeadlinePrecision;
use App\Enums\CommitmentSourceType;
use App\Models\User;
use App\Services\Commitments\CommitmentFingerprint;
use App\Services\Commitments\DTO\CommitmentCandidate;
use Carbon\CarbonImmutable;

final class CommunicationCommitmentExtractor
{
    /**
     * @param  array{
     *     text: string,
     *     source_type: CommitmentSourceType,
     *     source_id?: ?int,
     *     source_reference?: array<string, mixed>,
     *     person_id?: ?int,
     *     person_name?: ?string,
     *     project_id?: ?int,
     *     occurred_at?: ?CarbonImmutable,
     *     is_owner_command?: bool
     * }  $input
     */
    public function extract(User $user, array $input): ?CommitmentCandidate
    {
        if (($input['is_owner_command'] ?? false) === true) {
            return null;
        }

        $text = trim((string) ($input['text'] ?? ''));
        if ($text === '' || mb_strlen($text) < 8) {
            return null;
        }

        if (! $this->looksLikePromise($text)) {
            return null;
        }

        $personId = isset($input['person_id']) ? (int) $input['person_id'] : 0;
        if ($personId < 1) {
            return null;
        }

        $action = $this->clip($text, 240);
        $deadline = $this->deadline($text, $input['occurred_at'] ?? null);
        $sourceType = $input['source_type'] instanceof CommitmentSourceType
            ? $input['source_type']
            : CommitmentSourceType::Other;
        $fingerprint = CommitmentFingerprint::make(
            $user->id,
            $this->cluster($input),
            $personId,
            $input['person_name'] ?? null,
            $action,
            null,
            $deadline['raw'],
        );

        return new CommitmentCandidate(
            user: $user,
            action: $action,
            title: $this->title($text),
            expectedResult: null,
            deadlineRaw: $deadline['raw'],
            deadlineAt: $deadline['at'],
            deadlinePrecision: $deadline['precision'],
            confidence: CommitmentConfidence::High,
            sourceType: $sourceType,
            sourceId: $input['source_id'] ?? null,
            sourceReference: is_array($input['source_reference'] ?? null) ? $input['source_reference'] : [],
            personId: $personId,
            personNameRaw: $input['person_name'] ?? null,
            unresolvedPerson: false,
            projectId: $input['project_id'] ?? null,
            meetingId: null,
            organizationId: null,
            meetingAnalysisId: null,
            excerpt: $this->clip($text, 400),
            speaker: $input['person_name'] ?? null,
            observedAt: $input['occurred_at'] ?? CarbonImmutable::now(),
            fingerprint: $fingerprint,
            metadata: [
                'extracted_from' => $sourceType->value,
            ],
        );
    }

    public function looksLikePromise(string $text): bool
    {
        $haystack = mb_strtolower($text);

        return preg_match(
            '/\b(я\s+пришлю|я\s+отправлю|я\s+зроблю|сделаю|зроблю|пришлю|отправлю|відправлю|отправим|відправимо|send(?:ing)?\s+(?:the\s+)?(?:pdf|budget|file|contract|договор)|will\s+send|will\s+do)\b/u',
            $haystack,
        ) === 1;
    }

    /**
     * @return array{raw: ?string, at: ?CarbonImmutable, precision: CommitmentDeadlinePrecision}
     */
    private function deadline(string $text, ?CarbonImmutable $observed): array
    {
        $haystack = mb_strtolower($text);
        $base = $observed ?? CarbonImmutable::now();

        if (preg_match('/\b(завтра|tomorrow)\b/u', $haystack) === 1) {
            return ['raw' => 'tomorrow', 'at' => $base->addDay()->endOfDay(), 'precision' => CommitmentDeadlinePrecision::Date];
        }

        if (preg_match('/\b(пятниц|friday|п’ятниц)\b/u', $haystack) === 1) {
            $at = $base->next('Friday')->endOfDay();

            return ['raw' => 'Friday', 'at' => $at, 'precision' => CommitmentDeadlinePrecision::Date];
        }

        if (preg_match('/\b(сред|wednesday|серед)\b/u', $haystack) === 1) {
            $at = $base->next('Wednesday')->endOfDay();

            return ['raw' => 'Wednesday', 'at' => $at, 'precision' => CommitmentDeadlinePrecision::Date];
        }

        return ['raw' => null, 'at' => null, 'precision' => CommitmentDeadlinePrecision::Unknown];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function cluster(array $input): string
    {
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : 0;
        if ($projectId > 0) {
            return 'project:'.$projectId;
        }

        return 'source:'.($input['source_type'] instanceof CommitmentSourceType
            ? $input['source_type']->value
            : 'other');
    }

    private function title(string $text): string
    {
        $first = trim(preg_split('/[.!?]/u', $text)[0] ?? $text);

        return $this->clip($first, 120) ?: 'Commitment';
    }

    private function clip(string $text, int $max): string
    {
        $trimmed = trim($text);

        return $trimmed === '' ? '' : mb_substr($trimmed, 0, $max);
    }
}
