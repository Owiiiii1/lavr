<?php

namespace App\Enums;

enum ReadinessState: string
{
    case Ready = 'ready';
    case NeedsAttention = 'needs_attention';
    case NotConfigured = 'not_configured';
}
