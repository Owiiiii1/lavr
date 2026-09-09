<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\WebApp\TelegramWebAppAuthException;
use App\Services\Telegram\WebApp\TelegramWebAppInitDataValidator;
use Tests\TestCase;

class TelegramWebAppInitDataValidatorTest extends TestCase
{
    public function test_accepts_valid_signed_init_data(): void
    {
        $token = '123456:TEST-TOKEN';
        $authDate = now()->timestamp;
        $initData = $this->signInitData($token, [
            'auth_date' => (string) $authDate,
            'query_id' => 'AAEtest',
            'user' => json_encode([
                'id' => 910077,
                'first_name' => 'Owner',
                'username' => 'ceo',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $user = (new TelegramWebAppInitDataValidator)->validate($initData, $token, 86400);

        $this->assertSame('910077', $user->telegramUserId);
        $this->assertSame('ceo', $user->username);
        $this->assertSame($authDate, $user->authDate);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->expectException(TelegramWebAppAuthException::class);

        (new TelegramWebAppInitDataValidator)->validate(
            'auth_date='.now()->timestamp.'&hash='.str_repeat('a', 64).'&user='.rawurlencode('{"id":1}'),
            '123456:TEST-TOKEN',
            86400,
        );
    }

    public function test_rejects_expired_auth_date(): void
    {
        $token = '123456:TEST-TOKEN';
        $initData = $this->signInitData($token, [
            'auth_date' => (string) now()->subDays(3)->timestamp,
            'user' => json_encode(['id' => 910077], JSON_UNESCAPED_UNICODE),
        ]);

        try {
            (new TelegramWebAppInitDataValidator)->validate($initData, $token, 3600);
            $this->fail('Expired initData was accepted.');
        } catch (TelegramWebAppAuthException $exception) {
            $this->assertSame('expired', $exception->reason);
        }
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function signInitData(string $token, array $fields): string
    {
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }
        $check = implode("\n", $pairs);
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $check, $secret);

        return http_build_query($fields);
    }
}
