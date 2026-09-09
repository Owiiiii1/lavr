<?php

namespace App\Enums;

enum MeetingSourceType: string
{
    case ManualUpload = 'manual_upload';
    case ManualText = 'manual_text';
    case CalendarLink = 'calendar_link';
    case Zoom = 'zoom';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
