<?php

namespace App\Services\Telegram;

use App\Models\TelegramBotSetting;
use App\Services\Telegram\Exceptions\TelegramSendException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use SergiX44\Nutgram\Nutgram;
use Throwable;

class TelegramBotManager
{
    public function setting(): TelegramBotSetting
    {
        /** @var TelegramBotSetting $setting */
        $setting = TelegramBotSetting::query()->firstOrCreate([]);

        return $setting;
    }

    public function existingSetting(): ?TelegramBotSetting
    {
        return TelegramBotSetting::query()->first();
    }

    /**
     * @return array{id: int|null, username: string|null, first_name: string|null}
     */
    public function getMe(string $token): array
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $me = $bot->getMe();

                return [
                    'id' => $me->id ?? null,
                    'username' => $me->username ?? null,
                    'first_name' => $me->first_name ?? null,
                ];
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram getMe failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->get($this->apiUrl($token, 'getMe'));
        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram getMe failed: '.$response->body());
        }

        $result = $response->json('result') ?? [];

        return [
            'id' => $result['id'] ?? null,
            'username' => $result['username'] ?? null,
            'first_name' => $result['first_name'] ?? null,
        ];
    }

    public function setWebhook(string $token, string $url, string $secret): void
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $bot->setWebhook($url, secret_token: $secret, drop_pending_updates: false);

                return;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram setWebhook failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'setWebhook'), [
            'url' => $url,
            'secret_token' => $secret,
            'drop_pending_updates' => false,
        ]);

        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram setWebhook failed: '.$response->body());
        }
    }

    public function deleteWebhook(string $token): void
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $bot->deleteWebhook();

                return;
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram deleteWebhook failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'deleteWebhook'));
        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram deleteWebhook failed: '.$response->body());
        }
    }

    public function ensureWebhookSecret(TelegramBotSetting $setting): string
    {
        if (filled($setting->webhook_secret)) {
            return (string) $setting->webhook_secret;
        }

        $secret = Str::random(32);
        $setting->forceFill(['webhook_secret' => $secret])->save();

        return $secret;
    }

    public function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/telegram/webhook';
    }

    /**
     * @return array{
     *     url: string|null,
     *     has_custom_certificate: bool,
     *     pending_update_count: int,
     *     last_error_date: int|null,
     *     last_error_message: string|null,
     *     max_connections: int|null,
     *     ip_address: string|null
     * }
     */
    public function getWebhookInfo(string $token): array
    {
        try {
            if (class_exists(Nutgram::class)) {
                $bot = new Nutgram($token);
                $info = $bot->getWebhookInfo();

                return [
                    'url' => $info->url ?? null,
                    'has_custom_certificate' => (bool) ($info->has_custom_certificate ?? false),
                    'pending_update_count' => (int) ($info->pending_update_count ?? 0),
                    'last_error_date' => isset($info->last_error_date) ? (int) $info->last_error_date : null,
                    'last_error_message' => $info->last_error_message ?? null,
                    'max_connections' => isset($info->max_connections) ? (int) $info->max_connections : null,
                    'ip_address' => $info->ip_address ?? null,
                ];
            }
        } catch (Throwable $e) {
            throw new RuntimeException('Telegram getWebhookInfo failed: '.$e->getMessage(), 0, $e);
        }

        $response = Http::timeout(15)->get($this->apiUrl($token, 'getWebhookInfo'));
        if (! $response->successful() || ! ($response->json('ok') === true)) {
            throw new RuntimeException('Telegram getWebhookInfo failed: '.$response->body());
        }

        $result = $response->json('result') ?? [];

        return [
            'url' => $result['url'] ?? null,
            'has_custom_certificate' => (bool) ($result['has_custom_certificate'] ?? false),
            'pending_update_count' => (int) ($result['pending_update_count'] ?? 0),
            'last_error_date' => isset($result['last_error_date']) ? (int) $result['last_error_date'] : null,
            'last_error_message' => $result['last_error_message'] ?? null,
            'max_connections' => isset($result['max_connections']) ? (int) $result['max_connections'] : null,
            'ip_address' => $result['ip_address'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $replyMarkup
     * @return array{message_id: string}
     */
    public function sendTextMessage(string $chatId, string $text, ?array $replyMarkup = null): array
    {
        $token = (string) $this->setting()->bot_token;

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot token is missing.');
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyMarkup !== null && $replyMarkup !== []) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'sendMessage'), $payload);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw TelegramSendException::fromResponse($response);
        }

        $messageId = $response->json('result.message_id');

        return [
            'message_id' => $messageId === null || $messageId === '' ? '' : (string) $messageId,
        ];
    }

    /**
     * Native Telegram voice bubble. MP3 / OGG / M4A. No reply keyboard (the DM menu is persistent).
     *
     * @return array{message_id: string}
     */
    public function sendVoiceMessage(string $chatId, string $absolutePath, string $filename, string $mime = 'audio/mpeg'): array
    {
        $token = (string) $this->setting()->bot_token;

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot token is missing.');
        }

        if (! is_file($absolutePath) || filesize($absolutePath) < 1) {
            throw new RuntimeException('Telegram voice temp file is missing.');
        }

        $contents = file_get_contents($absolutePath);

        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('Telegram voice temp file is empty.');
        }

        $response = Http::timeout(30)
            ->attach('voice', $contents, $filename, ['Content-Type' => $mime])
            ->post($this->apiUrl($token, 'sendVoice'), [
                'chat_id' => $chatId,
            ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw TelegramSendException::fromResponse($response, 'Telegram sendVoice failed');
        }

        $messageId = $response->json('result.message_id');

        return [
            'message_id' => $messageId === null || $messageId === '' ? '' : (string) $messageId,
        ];
    }

    /**
     * @return 'connected'|'restricted'|'left'|'unknown'
     */
    public function botMembershipStatus(string $chatId): string
    {
        $token = (string) $this->setting()->bot_token;

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot token is missing.');
        }

        $me = Http::timeout(15)->get($this->apiUrl($token, 'getMe'));

        if (! $me->successful() || $me->json('ok') !== true) {
            throw new RuntimeException('Telegram getMe failed.');
        }

        $botId = $me->json('result.id');

        if ($botId === null) {
            throw new RuntimeException('Telegram getMe returned no bot id.');
        }

        $response = Http::timeout(15)->post($this->apiUrl($token, 'getChatMember'), [
            'chat_id' => $chatId,
            'user_id' => $botId,
        ]);

        if (! $response->successful() || $response->json('ok') !== true) {
            $class = TelegramSendException::classify(
                (string) ($response->json('description') ?? ''),
                (int) ($response->json('error_code') ?? $response->status()),
            );

            return in_array($class, ['kicked', 'not_found', 'forbidden'], true)
                ? 'left'
                : 'unknown';
        }

        $status = (string) ($response->json('result.status') ?? '');

        return match ($status) {
            'left', 'kicked' => 'left',
            'restricted' => 'restricted',
            'member', 'administrator', 'creator' => 'connected',
            default => 'unknown',
        };
    }

    private function apiUrl(string $token, string $method): string
    {
        return 'https://api.telegram.org/bot'.$token.'/'.$method;
    }
}
