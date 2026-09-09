<?php

namespace App\Services\Telegram\WebApp;

use RuntimeException;

final class TelegramWebAppAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : $reason);
    }

    public static function invalid(): self
    {
        return new self('invalid', 'Telegram authorization is invalid.');
    }

    public static function expired(): self
    {
        return new self('expired', 'Telegram authorization has expired.');
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'Telegram WebApp is unavailable.');
    }

    public static function notLinked(): self
    {
        return new self('not_linked', 'Telegram account is not linked to this LAVR instance.');
    }
}
