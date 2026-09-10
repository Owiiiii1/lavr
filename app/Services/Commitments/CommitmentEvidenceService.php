<?php

namespace App\Services\Commitments;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentSourceType;
use App\Models\Commitment;
use App\Models\CommitmentEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

final class CommitmentEvidenceService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function add(
        Commitment $commitment,
        CommitmentEvidenceType $type,
        CommitmentSourceType $sourceType,
        ?int $sourceId,
        ?string $excerpt,
        CommitmentConfidence $confidence,
        ?CarbonImmutable $observedAt = null,
        array $metadata = [],
    ): CommitmentEvidence {
        $clipped = $this->clip($excerpt);

        $existing = CommitmentEvidence::query()
            ->where('commitment_id', $commitment->id)
            ->where('evidence_type', $type->value)
            ->where('source_type', $sourceType->value)
            ->where('source_id', $sourceId)
            ->where('excerpt', $clipped)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $row = CommitmentEvidence::query()->create([
            'commitment_id' => $commitment->id,
            'evidence_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'excerpt' => $clipped,
            'observed_at' => $observedAt ?? now(),
            'confidence' => $confidence,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        Log::info('commitment evidence added', [
            'commitment_id' => $commitment->id,
            'person_id' => $commitment->person_id,
            'evidence_type' => $type->value,
            'source_type' => $sourceType->value,
            'source_id' => $sourceId,
            'outcome' => 'created',
        ]);

        return $row;
    }

    public function hasCompletionSignal(Commitment $commitment): bool
    {
        return $commitment->evidence()
            ->whereIn('evidence_type', [
                CommitmentEvidenceType::Delivery->value,
                CommitmentEvidenceType::Completion->value,
            ])
            ->exists();
    }

    private function clip(?string $excerpt): ?string
    {
        if ($excerpt === null) {
            return null;
        }

        $trimmed = trim($excerpt);

        if ($trimmed === '') {
            return null;
        }

        $limit = CommitmentConfig::maxExcerptChars();

        if (mb_strlen($trimmed) <= $limit) {
            return $trimmed;
        }

        return rtrim(mb_substr($trimmed, 0, $limit - 1)).'…';
    }
}
