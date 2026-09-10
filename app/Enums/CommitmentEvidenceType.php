<?php

namespace App\Enums;

enum CommitmentEvidenceType: string
{
    case Promise = 'promise';
    case Deadline = 'deadline';
    case Progress = 'progress';
    case Delivery = 'delivery';
    case Completion = 'completion';
    case Confirmation = 'confirmation';
    case Cancellation = 'cancellation';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
