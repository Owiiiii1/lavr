<?php

namespace Tests\Unit\Telegram;

use App\Services\Telegram\WebApp\TelegramWebAppAuthException;
use App\Services\Telegram\WebApp\TelegramWebAppInitDataValidator;
use Illuminate\Support\Carbon;
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

    public function test_accepts_published_telegram_mini_apps_hmac_vector(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1662771648));

        $user = (new TelegramWebAppInitDataValidator)->validate(
            'query_id=AAHdF6IQAAAAAN0XohDhrOrc&user=%7B%22id%22%3A279058397%2C%22first_name%22%3A%22Vladislav%22%2C%22last_name%22%3A%22Kibenko%22%2C%22username%22%3A%22vdkfrost%22%2C%22language_code%22%3A%22ru%22%2C%22is_premium%22%3Atrue%7D&auth_date=1662771648&hash=c501b71e775f74ce10e377dea85a7ea24ecd640b223ea86dfe453e0eaed2e2b2',
            '5768337691:AAH5YkoiEuPk8-FZa32hStHTqXiLPtAEhx8',
            86400,
        );

        $this->assertSame('279058397', $user->telegramUserId);
        $this->assertSame('vdkfrost', $user->username);
        $this->assertSame(1662771648, $user->authDate);
    }

    public function test_accepts_init_data_when_unsigned_signature_field_is_present(): void
    {
        $token = '123456:TEST-TOKEN';
        $initData = $this->signInitData($token, [
            'auth_date' => (string) now()->timestamp,
            'user' => json_encode(['id' => 910077], JSON_UNESCAPED_UNICODE),
        ]).'&signature=not-a-telegram-signature';

        $user = (new TelegramWebAppInitDataValidator)->validate($initData, $token, 86400);

        $this->assertSame('910077', $user->telegramUserId);
    }

    public function test_accepts_plus_in_user_name(): void
    {
        $token = '123456:TEST-TOKEN';
        $initData = $this->signInitData($token, [
            'auth_date' => (string) now()->timestamp,
            'user' => json_encode([
                'id' => 910077,
                'first_name' => 'Vladislav + - ? /',
            ], JSON_UNESCAPED_UNICODE),
        ]);

        $user = (new TelegramWebAppInitDataValidator)->validate($initData, $token, 86400);

        $this->assertSame('Vladislav + - ? /', $user->firstName);
    }

    public function test_accepts_published_third_party_ed25519_vector(): void
    {
        $this->travelTo(Carbon::createFromTimestamp(1733584787));

        $user = (new TelegramWebAppInitDataValidator)->validate(
            'user=%7B%22id%22%3A279058397%2C%22first_name%22%3A%22Vladislav%20%2B%20-%20%3F%20%5C%2F%22%2C%22last_name%22%3A%22Kibenko%22%2C%22username%22%3A%22vdkfrost%22%2C%22language_code%22%3A%22ru%22%2C%22is_premium%22%3Atrue%2C%22allows_write_to_pm%22%3Atrue%2C%22photo_url%22%3A%22https%3A%5C%2F%5C%2Ft.me%5C%2Fi%5C%2Fuserpic%5C%2F320%5C%2F4FPEE4tmP3ATHa57u6MqTDih13LTOiMoKoLDRG4PnSA.svg%22%7D&chat_instance=8134722200314281151&chat_type=private&auth_date=1733584787&hash=2174df5b000556d044f3f020384e879c8efcab55ddea2ced4eb752e93e7080d6&signature=zL-ucjNyREiHDE8aihFwpfR9aggP2xiAo3NSpfe-p7IbCisNlDKlo7Kb6G4D0Ao2mBrSgEk4maLSdv6MLIlADQ',
            '7342037359:dummy-token-hmac-must-not-match',
            86400,
        );

        $this->assertSame('279058397', $user->telegramUserId);
        $this->assertSame('vdkfrost', $user->username);
    }

    public function test_rebuilds_init_data_split_across_form_fields(): void
    {
        $token = '123456:TEST-TOKEN';
        $initData = $this->signInitData($token, [
            'auth_date' => (string) now()->timestamp,
            'query_id' => 'AAEtest',
            'user' => json_encode(['id' => 910077], JSON_UNESCAPED_UNICODE),
        ]);

        parse_str($initData, $fields);
        $first = array_key_first($fields);
        $truncated = $first.'='.$fields[$first];
        unset($fields[$first]);

        $validator = new TelegramWebAppInitDataValidator;
        $user = $validator->validate($validator->coalesce($truncated, $fields), $token, 86400);

        $this->assertSame('910077', $user->telegramUserId);
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

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
