<?php

namespace App\Services\Automation;

use App\Enums\AutomationRunOutcome;

final readonly class AutomationResult
{
    /**
     * @param  array<string, mixed>  $metrics
     */
    public function __construct(
        public AutomationRunOutcome $status,
        public string $reasonCode,
        public string $safeSummary,
        public int $durationMs,
        public array $metrics,
        public ?string $deliveryStatus = null,
        public ?string $errorClass = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function of(
        AutomationRunOutcome $status,
        string $reasonCode,
        string $safeSummary = '',
        int $durationMs = 0,
        array $metrics = [],
        ?string $deliveryStatus = null,
        ?string $errorClass = null,
    ): self {
        return new self($status, $reasonCode, $safeSummary, $durationMs, $metrics, $deliveryStatus, $errorClass);
    }
}
