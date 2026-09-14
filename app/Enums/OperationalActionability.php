<?php

namespace App\Enums;

enum OperationalActionability: string
{
    case Informational = 'informational';
    case Review = 'review';
    case FollowUp = 'follow_up';
    case Approve = 'approve';
    case ExecutePossible = 'execute_possible';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $value): string => $value->value, self::cases());
    }
}
