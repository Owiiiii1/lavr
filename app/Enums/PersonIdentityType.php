<?php

namespace App\Enums;

enum PersonIdentityType: string
{
    case Email = 'email';
    case Phone = 'phone';
    case TelegramUserId = 'telegram_user_id';
    case TelegramUsername = 'telegram_username';
    case ZoomEmail = 'zoom_email';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
