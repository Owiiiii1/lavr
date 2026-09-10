<?php

namespace App\Services\Automation;

final readonly class SourceCollection
{
    /**
     * @param  list<array<string, mixed>>  $data
     */
    public function __construct(
        public string $name,
        public string $status,
        public array $data,
        public ?string $freshness = null,
        public ?string $errorSafe = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === 'ok';
    }
}
