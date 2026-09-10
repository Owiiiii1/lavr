<?php

namespace App\Services\Commitments\DTO;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentDeadlinePrecision;
use App\Enums\CommitmentSourceType;
use App\Models\User;
use Carbon\CarbonImmutable;

final readonly class CommitmentCandidate
{
    /**
     * @param  array<string, mixed>  $sourceReference
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public User $user,
        public string $action,
        public string $title,
        public ?string $expectedResult,
        public ?string $deadlineRaw,
        public ?CarbonImmutable $deadlineAt,
        public CommitmentDeadlinePrecision $deadlinePrecision,
        public CommitmentConfidence $confidence,
        public CommitmentSourceType $sourceType,
        public ?int $sourceId,
        public array $sourceReference,
        public ?int $personId,
        public ?string $personNameRaw,
        public bool $unresolvedPerson,
        public ?int $projectId,
        public ?int $meetingId,
        public ?int $organizationId,
        public ?int $meetingAnalysisId,
        public ?string $excerpt,
        public ?string $speaker,
        public ?CarbonImmutable $observedAt,
        public string $fingerprint,
        public array $metadata = [],
    ) {}
}
