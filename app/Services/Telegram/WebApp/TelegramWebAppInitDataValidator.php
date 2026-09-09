<?php

namespace App\Services\Telegram\WebApp;

final class TelegramWebAppInitDataValidator
{
    /**
     * Validate Telegram Mini App initData (HMAC-SHA256) and auth_date freshness.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public function validate(string $initData, string $botToken, int $maxAgeSeconds): TelegramWebAppUser
    {
        $initData = trim($initData);

        if ($initData === '' || $botToken === '') {
            throw TelegramWebAppAuthException::invalid();
        }

        $fields = [];
        parse_str($initData, $fields);

        if (! is_array($fields)) {
            throw TelegramWebAppAuthException::invalid();
        }

        $hash = strtolower(trim((string) ($fields['hash'] ?? '')));
        unset($fields['hash'], $fields['signature']);

        if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw TelegramWebAppAuthException::invalid();
        }

        $pairs = [];
        foreach ($fields as $key => $value) {
            if (! is_string($key) || $key === '' || is_array($value)) {
                continue;
            }

            $pairs[] = $key.'='.$value;
        }

        sort($pairs, SORT_STRING);
        $dataCheckString = implode("\n", $pairs);
        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computed = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($computed, $hash)) {
            throw TelegramWebAppAuthException::invalid();
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate < 1) {
            throw TelegramWebAppAuthException::invalid();
        }

        if (abs(now()->timestamp - $authDate) > max(60, $maxAgeSeconds)) {
            throw TelegramWebAppAuthException::expired();
        }

        $userRaw = (string) ($fields['user'] ?? '');
        $user = json_decode($userRaw, true);

        if (! is_array($user) || ! isset($user['id'])) {
            throw TelegramWebAppAuthException::invalid();
        }

        $telegramUserId = (string) $user['id'];

        if ($telegramUserId === '' || ! preg_match('/^\d+$/', $telegramUserId)) {
            throw TelegramWebAppAuthException::invalid();
        }

        $startParam = $fields['start_param'] ?? null;

        return new TelegramWebAppUser(
            telegramUserId: $telegramUserId,
            username: isset($user['username']) ? (string) $user['username'] : null,
            firstName: isset($user['first_name']) ? (string) $user['first_name'] : null,
            lastName: isset($user['last_name']) ? (string) $user['last_name'] : null,
            startParam: is_string($startParam) && $startParam !== '' ? $startParam : null,
            authDate: $authDate,
        );
    }
}
