<?php

namespace App\Enums;

enum OperationalSeverity: string
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
        return array_map(fn (self $severity): string => $severity->value, self::cases());
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Normal => 2,
            self::Low => 1,
        };
    }

    public function atLeast(self $minimum): bool
    {
        return $this->rank() >= $minimum->rank();
    }

    public static function higher(self $left, self $right): self
    {
        return $left->rank() >= $right->rank() ? $left : $right;
    }
}
