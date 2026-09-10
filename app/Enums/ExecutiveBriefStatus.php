<?php

namespace App\Enums;

enum ExecutiveBriefStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
