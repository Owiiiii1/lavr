<?php

namespace App\Services\Telegram\WebApp;

final class TelegramWebAppUser
{
    public function __construct(
        public readonly string $telegramUserId,
        public readonly ?string $username,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $startParam,
        public readonly int $authDate,
    ) {}
}
