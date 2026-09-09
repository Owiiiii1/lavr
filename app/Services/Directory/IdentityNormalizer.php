<?php

namespace App\Services\Directory;

use App\Enums\PersonIdentityType;

final class IdentityNormalizer
{
    public static function normalize(PersonIdentityType $type, string $value): string
    {
        $trimmed = trim($value);

        return match ($type) {
            PersonIdentityType::Email, PersonIdentityType::ZoomEmail => mb_strtolower($trimmed),
            PersonIdentityType::TelegramUsername => mb_strtolower(ltrim($trimmed, '@')),
            PersonIdentityType::Phone => (string) preg_replace('/[^\d+]/', '', $trimmed),
            PersonIdentityType::TelegramUserId, PersonIdentityType::Other => mb_strtolower($trimmed),
        };
    }
}
