<?php

namespace App\Services\OperationalControl;

use App\Enums\OperationalActionability;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalType;
use Carbon\CarbonImmutable;

final readonly class OperationalRuleMatch
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public OperationalEventType $eventType,
        public OperationalSeverity $severity,
        public string $fingerprint,
        public string $rationale,
        public ProactiveProposalType $proposalType,
        public string $title,
        public string $recommendedAction,
        public OperationalActionability $actionability,
        public CarbonImmutable $occurredAt,
        public array $evidence = [],
        public array $payload = [],
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?string $sourceExternalId = null,
        public ?int $personId = null,
        public ?int $projectId = null,
        public ?int $meetingId = null,
        public ?int $commitmentId = null,
        public ?string $confidence = 'high',
        public ?string $evidencePointer = null,
        public ?string $href = null,
    ) {}
}
