<?php

namespace App\Services\Telegram\WebApp;

use Illuminate\Support\Facades\Log;
use Throwable;

final class TelegramWebAppInitDataValidator
{
    /**
     * Production Ed25519 public key published by Telegram for third-party Mini App validation.
     */
    private const TELEGRAM_ED25519_PRODUCTION = 'e7bf03a2fa4602af4580703d88dda5bb59f32ed8b02a56c187fe7d34caed242d';

    /**
     * @var list<string>
     */
    private const INIT_DATA_KEYS = [
        'query_id',
        'user',
        'receiver',
        'chat',
        'chat_type',
        'chat_instance',
        'start_param',
        'can_send_after',
        'auth_date',
        'hash',
        'signature',
    ];

    /**
     * Validate Telegram Mini App initData (HMAC-SHA256) and auth_date freshness.
     *
     * @see https://core.telegram.org/bots/webapps#validating-data-received-via-the-mini-app
     */
    public function validate(string $initData, string $botToken, int $maxAgeSeconds): TelegramWebAppUser
    {
        $initData = trim($initData);
        $botToken = trim($botToken);

        if ($initData === '' || $botToken === '') {
            $this->logInvalid('empty', []);
            throw TelegramWebAppAuthException::invalid();
        }

        $fields = $this->parseInitData($initData);
        $hash = strtolower(trim((string) ($fields['hash'] ?? '')));
        $signature = trim((string) ($fields['signature'] ?? ''));

        unset($fields['hash']);

        $hmacFields = $fields;
        unset($hmacFields['signature']);

        $hmacOk = $hash !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1
            && (
                $this->hmacMatches($hmacFields, $hash, $botToken)
                || $this->hmacMatches($fields, $hash, $botToken)
            );

        $ed25519Ok = $signature !== '' && $this->ed25519Matches($hmacFields, $signature, $botToken);

        if (! $hmacOk && ! $ed25519Ok) {
            $this->logInvalid('signature', array_keys($fields + ['hash' => $hash]));
            throw TelegramWebAppAuthException::invalid();
        }

        $authDate = (int) ($fields['auth_date'] ?? 0);

        if ($authDate < 1) {
            $this->logInvalid('auth_date', array_keys($fields));
            throw TelegramWebAppAuthException::invalid();
        }

        if (abs(now()->timestamp - $authDate) > max(60, $maxAgeSeconds)) {
            throw TelegramWebAppAuthException::expired();
        }

        $userRaw = (string) ($fields['user'] ?? '');
        $user = json_decode($userRaw, true);

        if (! is_array($user) || ! isset($user['id'])) {
            $this->logInvalid('user', array_keys($fields));
            throw TelegramWebAppAuthException::invalid();
        }

        $telegramUserId = (string) $user['id'];

        if ($telegramUserId === '' || preg_match('/^\d+$/', $telegramUserId) !== 1) {
            $this->logInvalid('user_id', array_keys($fields));
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

    /**
     * Rebuild initData when a form-urlencoded POST split query pairs into extra request fields.
     *
     * @param  array<string, mixed>  $requestFields
     */
    public function coalesce(string $initData, array $requestFields): string
    {
        if (str_contains($initData, 'hash=') || str_contains($initData, 'signature=')) {
            return $initData;
        }

        $fields = $this->parseInitData($initData);

        foreach (self::INIT_DATA_KEYS as $key) {
            if (! array_key_exists($key, $requestFields)) {
                continue;
            }

            $value = $requestFields[$key];

            if (! is_string($value) || $value === '') {
                continue;
            }

            $fields[$key] = $value;
        }

        if (! isset($fields['hash']) && ! isset($fields['signature'])) {
            return $initData;
        }

        $pairs = [];

        foreach ($fields as $key => $value) {
            $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * @return array<string, string>
     */
    private function parseInitData(string $initData): array
    {
        $fields = [];

        foreach (explode('&', $initData) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $key = rawurldecode($parts[0]);

            if ($key === '') {
                continue;
            }

            $fields[$key] = rawurldecode($parts[1] ?? '');
        }

        return $fields;
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function hmacMatches(array $fields, string $hash, string $botToken): bool
    {
        $pairs = $this->sortedPairs($fields);

        if ($pairs === []) {
            return false;
        }

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computed = hash_hmac('sha256', implode("\n", $pairs), $secretKey);

        return hash_equals($computed, $hash);
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function ed25519Matches(array $fields, string $signature, string $botToken): bool
    {
        $botId = explode(':', $botToken, 2)[0];

        if ($botId === '' || ! ctype_digit($botId)) {
            return false;
        }

        $pairs = $this->sortedPairs($fields);

        if ($pairs === []) {
            return false;
        }

        $message = $botId.':WebAppData'."\n".implode("\n", $pairs);
        $signatureBin = $this->decodeUrlSafeBase64($signature);

        try {
            $publicKey = sodium_hex2bin(self::TELEGRAM_ED25519_PRODUCTION);
        } catch (Throwable) {
            return false;
        }

        if ($signatureBin === null
            || strlen($signatureBin) !== SODIUM_CRYPTO_SIGN_BYTES
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($signatureBin, $message, $publicKey);
        } catch (Throwable) {
            return false;
        }
    }

    private function decodeUrlSafeBase64(string $value): ?string
    {
        $normalized = rtrim($value, '=');

        try {
            return sodium_base642bin($normalized, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        } catch (Throwable) {
            try {
                $padded = $normalized.str_repeat('=', (4 - strlen($normalized) % 4) % 4);

                return sodium_base642bin($padded, SODIUM_BASE64_VARIANT_URLSAFE);
            } catch (Throwable) {
                return null;
            }
        }
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<string>
     */
    private function sortedPairs(array $fields): array
    {
        ksort($fields, SORT_STRING);

        $pairs = [];

        foreach ($fields as $key => $value) {
            if ($key === '' || ! is_string($value)) {
                continue;
            }

            $pairs[] = $key.'='.$value;
        }

        return $pairs;
    }

    /**
     * @param  list<string>|array<int|string, mixed>  $fieldKeys
     */
    private function logInvalid(string $detail, array $fieldKeys): void
    {
        Log::info('telegram_webapp_auth', [
            'outcome' => 'invalid',
            'detail' => $detail,
            'field_keys' => array_values(array_map('strval', $fieldKeys)),
        ]);
    }
}
