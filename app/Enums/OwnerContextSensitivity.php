<?php

namespace App\Enums;

enum OwnerContextSensitivity: string
{
    case Normal = 'normal';
    case Private = 'private';
    case Restricted = 'restricted';

    public static function tryFromLoose(mixed $value): ?self
    {
        return self::tryFrom(mb_strtolower(trim((string) $value)));
    }
}
