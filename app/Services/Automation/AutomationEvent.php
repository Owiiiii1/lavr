<?php

namespace App\Services\Automation;

final readonly class AutomationEvent
{
    /**
     * @param  array<string, scalar|null>  $payload
     */
    public function __construct(
        public string $type,
        public int $userId,
        public ?int $sourceId = null,
        public array $payload = [],
    ) {}
}
