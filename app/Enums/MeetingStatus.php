<?php

namespace App\Enums;

enum MeetingStatus: string
{
    case Draft = 'draft';
    case Ready = 'ready';
    case Archived = 'archived';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
