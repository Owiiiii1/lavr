<?php

namespace App\Enums;

enum SourceBindingKind: string
{
    case Explicit = 'explicit';
    case Suggested = 'suggested';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $kind): string => $kind->value, self::cases());
    }
}
