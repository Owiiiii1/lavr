<?php

namespace App\Enums;

enum MeetingAnalysisStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
