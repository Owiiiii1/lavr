<?php

namespace App\Enums;

enum OwnerContextCategory: string
{
    case Identity = 'identity';
    case Communication = 'communication';
    case CeoGoal = 'ceo_goal';
    case CeoOperatingRule = 'ceo_operating_rule';
    case CeoDevelopment = 'ceo_development';
    case BusinessContext = 'business_context';
    case BusinessRule = 'business_rule';
    case Priority = 'priority';
    case RoleContext = 'role_context';
    case PersonalConstraint = 'personal_constraint';
    case Other = 'other';

    public static function tryFromLoose(mixed $value): ?self
    {
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));

        return self::tryFrom($key);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
