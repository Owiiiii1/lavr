<?php

namespace App\Enums;

enum IntegrationHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Blocked = 'blocked';
    case Disabled = 'disabled';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $health): string => $health->value, self::cases());
    }
}
