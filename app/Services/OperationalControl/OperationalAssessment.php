<?php

namespace App\Services\OperationalControl;

use App\Enums\OperationalActionability;
use App\Enums\OperationalSeverity;

final readonly class OperationalAssessment
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public OperationalSeverity $severity,
        public bool $important,
        public bool $urgent,
        public bool $actionable,
        public bool $notify,
        public OperationalActionability $actionability,
        public string $recommendedNextStep,
        public array $context = [],
    ) {}
}
