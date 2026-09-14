<?php

namespace App\Enums;

enum ProjectSourceType: string
{
    case IntegrationAccount = 'integration_account';
    case GoogleMailbox = 'google_mailbox';
    case GoogleCalendar = 'google_calendar';
    case TelegramGroup = 'telegram_group';
    case Conversation = 'conversation';
    case StoredFile = 'stored_file';
    case Zoom = 'zoom';
    case ExternalApi = 'external_api';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }

    public function isGoogleMailbox(): bool
    {
        return $this === self::GoogleMailbox || $this === self::IntegrationAccount;
    }
}
