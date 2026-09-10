<?php

namespace App\Enums;

enum ExecutiveBriefPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $priority): string => $priority->value, self::cases());
    }

    public function scoreFloor(): int
    {
        return match ($this) {
            self::Critical => 80,
            self::High => 60,
            self::Normal => 35,
            self::Low => 0,
        };
    }
}
