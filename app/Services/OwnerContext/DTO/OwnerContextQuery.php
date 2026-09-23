<?php

namespace App\Services\OwnerContext\DTO;

final class OwnerContextQuery
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public ?string $question = null,
        public ?string $task = null,
        public ?int $personId = null,
        public ?int $projectId = null,
        public ?int $organizationId = null,
        public array $categories = [],
        public bool $includePrivate = false,
        public bool $includeRestricted = false,
        public int $limit = 6,
    ) {}
}
