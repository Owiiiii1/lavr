<?php

namespace App\Enums;

enum CommitmentDeadlinePrecision: string
{
    case ExactDatetime = 'exact_datetime';
    case Date = 'date';
    case Week = 'week';
    case Month = 'month';
    case Relative = 'relative';
    case Unknown = 'unknown';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $precision): string => $precision->value, self::cases());
    }

    public static function fromLoose(mixed $value): self
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : '';

        return self::tryFrom($raw) ?? self::Unknown;
    }
}
