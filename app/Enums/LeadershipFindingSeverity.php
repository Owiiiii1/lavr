<?php

namespace App\Enums;

enum LeadershipFindingSeverity: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $severity): string => $severity->value, self::cases());
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }
}
