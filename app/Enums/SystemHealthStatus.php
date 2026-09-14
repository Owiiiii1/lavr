<?php

namespace App\Enums;

enum SystemHealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Blocked = 'blocked';
    case Disabled = 'disabled';
    case NotConfigured = 'not_configured';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status): string => $status->value, self::cases());
    }
}
