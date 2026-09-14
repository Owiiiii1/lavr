<?php

namespace App\Enums;

enum SourceItemType: string
{
    case GmailMessage = 'gmail_message';
    case CalendarEvent = 'calendar_event';
    case TelegramMessage = 'telegram_message';
    case Zoom = 'zoom';
    case ExternalApi = 'external_api';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
