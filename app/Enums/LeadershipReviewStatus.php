<?php

namespace App\Enums;

enum LeadershipReviewStatus: string
{
    case Generating = 'generating';
    case Ready = 'ready';
    case Partial = 'partial';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case InsufficientData = 'insufficient_data';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
