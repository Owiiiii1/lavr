<?php

namespace App\Enums;

enum OwnerContextScopeType: string
{
    case Owner = 'owner';
    case Business = 'business';
    case Organization = 'organization';
    case Project = 'project';
    case Person = 'person';

    public static function tryFromLoose(mixed $value): ?self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value)));
    }

    public function needsEntity(): bool
    {
        return match ($this) {
            self::Organization, self::Project, self::Person => true,
            default => false,
        };
    }
}
