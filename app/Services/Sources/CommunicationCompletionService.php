<?php

namespace App\Services\Sources;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Models\Commitment;
use App\Services\Commitments\CommitmentEvidenceService;
use App\Services\Commitments\CommitmentStatusService;

final class CommunicationCompletionService
{
    public function __construct(
        private readonly CommitmentEvidenceService $evidence,
        private readonly CommitmentStatusService $statuses,
        private readonly CommunicationCompletionClassifier $classifier = new CommunicationCompletionClassifier,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function apply(
        Commitment $commitment,
        string $text,
        CommitmentSourceType $sourceType,
        ?int $sourceId,
        array $metadata = [],
    ): Commitment {
        if ($commitment->lifecycle_status?->isTerminal()) {
            return $commitment;
        }

        if ($this->classifier->isWeak($text) || ($this->classifier->isProgress($text) && ! $this->classifier->isStrongCompletion($text))) {
            $this->evidence->add(
                $commitment,
                CommitmentEvidenceType::Progress,
                $sourceType,
                $sourceId,
                $text,
                CommitmentConfidence::Medium,
                null,
                $metadata,
            );

            return $commitment->fresh(['evidence']) ?? $commitment;
        }

        if (! $this->classifier->isStrongCompletion($text)) {
            return $commitment;
        }

        $this->evidence->add(
            $commitment,
            CommitmentEvidenceType::Delivery,
            $sourceType,
            $sourceId,
            $text,
            CommitmentConfidence::High,
            null,
            $metadata,
        );

        if (in_array($commitment->lifecycle_status, [
            CommitmentLifecycleStatus::Detected,
            CommitmentLifecycleStatus::Open,
            CommitmentLifecycleStatus::LikelyDone,
        ], true)) {
            return $this->statuses->setLifecycle(
                $commitment,
                CommitmentLifecycleStatus::LikelyDone,
                $commitment->user,
                'source_delivery_evidence',
            );
        }

        return $commitment->fresh(['evidence']) ?? $commitment;
    }
}
