<?php

namespace App\Enums;

enum LeadershipFindingConfidence: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $confidence): string => $confidence->value, self::cases());
    }
}
