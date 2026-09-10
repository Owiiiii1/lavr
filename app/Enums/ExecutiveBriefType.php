<?php

namespace App\Enums;

enum ExecutiveBriefType: string
{
    case Morning = 'morning';
    case Evening = 'evening';
    case Weekly = 'weekly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
