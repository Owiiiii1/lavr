<?php

namespace App\Enums;

enum LeadershipReviewType: string
{
    case Owner = 'owner';
    case Project = 'project';
    case Meeting = 'meeting';
    case Team = 'team';
    case Person = 'person';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
