<?php

namespace App\Enums;

enum ZoomWebhookEventStatus: string
{
    case Received = 'received';
    case Dispatched = 'dispatched';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
}
