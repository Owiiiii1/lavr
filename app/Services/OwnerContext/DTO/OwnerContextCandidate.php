<?php

namespace App\Services\OwnerContext\DTO;

use App\Enums\OwnerContextCategory;
use App\Enums\OwnerContextFactClass;
use App\Enums\OwnerContextScopeType;
use App\Enums\OwnerContextSensitivity;

final class OwnerContextCandidate
{
    public function __construct(
        public string $value,
        public OwnerContextCategory $category,
        public OwnerContextFactClass $factClass,
        public OwnerContextScopeType $scopeType,
        public ?string $scopeLabel,
        public OwnerContextSensitivity $sensitivity,
        public ?float $confidence,
        public ?string $evidenceExcerpt,
    ) {}
}
