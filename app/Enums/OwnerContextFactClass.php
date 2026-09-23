<?php

namespace App\Enums;

enum OwnerContextFactClass: string
{
    case Fact = 'fact';
    case Current = 'current';
    case Historical = 'historical';
    case Analysis = 'analysis';
    case ToVerify = 'to_verify';

    public static function tryFromLoose(mixed $value): ?self
    {
        $key = str_replace([' ', '-'], '_', mb_strtolower(trim((string) $value)));

        return self::tryFrom($key);
    }
}
