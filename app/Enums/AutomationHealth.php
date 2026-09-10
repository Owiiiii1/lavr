<?php

namespace App\Enums;

enum AutomationHealth: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Blocked = 'blocked';
    case Disabled = 'disabled';
}
