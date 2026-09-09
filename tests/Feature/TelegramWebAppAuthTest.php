<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChannelIdentity;
use App\Models\TelegramBotSetting;
use App\Models\User;
use App\Services\Telegram\TelegramBotManager;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class TelegramWebAppAuthTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_can_open_webapp_boot_page(): void
    {
        $this->get('/telegram/webapp')
            ->assertOk()
            ->assertSee('LAVR');
    }

    public function test_valid_init_data_for_linked_owner_establishes_session(): void
    {
        $owner = $this->existingOwner();
        $usersBefore = User::query()->count();
        [$telegramId, $restore] = $this->bindOwnerTelegram($owner, '910088');

        try {
            $this->fakeBotToken('123456:TEST-TOKEN');
            $this->get('/telegram/webapp')->assertOk();
            $sessionBefore = session()->getId();

            $this->post('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId),
            ])
                ->assertRedirect('/lavr/today');

            $this->assertAuthenticatedAs($owner);
            $this->assertNotSame($sessionBefore, session()->getId());
            $this->assertSame($usersBefore, User::query()->count());
        } finally {
            $restore();
        }
    }

    public function test_form_urlencoded_split_init_data_still_establishes_session(): void
    {
        $owner = $this->existingOwner();
        [$telegramId, $restore] = $this->bindOwnerTelegram($owner, '910088');

        try {
            $this->fakeBotToken('123456:TEST-TOKEN');
            $raw = $this->signInitData('123456:TEST-TOKEN', $telegramId);
            parse_str($raw, $fields);
            $first = array_key_first($fields);
            $payload = $fields;
            $payload['init_data'] = $first.'='.$fields[$first];
            unset($payload[$first]);

            $this->post('/telegram/webapp/session', $payload)
                ->assertRedirect('/lavr/today');

            $this->assertAuthenticatedAs($owner);
        } finally {
            $restore();
        }
    }

    public function test_json_init_data_establishes_session(): void
    {
        $owner = $this->existingOwner();
        [$telegramId, $restore] = $this->bindOwnerTelegram($owner, '910088');

        try {
            $this->fakeBotToken('123456:TEST-TOKEN');

            $this->postJson('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId),
            ])->assertRedirect('/lavr/today');

            $this->assertAuthenticatedAs($owner);
        } finally {
            $restore();
        }
    }

    public function test_invalid_signature_does_not_login_or_create_user(): void
    {
        $usersBefore = User::query()->count();
        $this->fakeBotToken('123456:TEST-TOKEN');

        $response = $this->post('/telegram/webapp/session', [
            'init_data' => 'auth_date='.now()->timestamp.'&user='.rawurlencode('{"id":910088}').'&hash='.str_repeat('ab', 32),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('"reason":"invalid"', html_entity_decode($response->getContent()));

        $this->assertGuest();
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_expired_init_data_is_rejected(): void
    {
        $owner = $this->existingOwner();
        [$telegramId, $restore] = $this->bindOwnerTelegram($owner, '910088');

        try {
            $this->fakeBotToken('123456:TEST-TOKEN');
            config(['telegram.webapp.auth_max_age' => 60]);

            $response = $this->post('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId, now()->subHours(3)->timestamp),
            ]);

            $response->assertOk();
            $this->assertStringContainsString('"reason":"expired"', html_entity_decode($response->getContent()));

            $this->assertGuest();
        } finally {
            $restore();
        }
    }

    public function test_unknown_telegram_user_is_rejected_without_creating_a_user(): void
    {
        $usersBefore = User::query()->count();
        $this->fakeBotToken('123456:TEST-TOKEN');

        $response = $this->post('/telegram/webapp/session', [
            'init_data' => $this->signInitData('123456:TEST-TOKEN', '910099'),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('"reason":"not_linked"', html_entity_decode($response->getContent()));

        $this->assertGuest();
        $this->assertNull(ChannelIdentity::findTelegramByExternalUserId('910099'));
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_arbitrary_deep_link_is_ignored_after_successful_auth(): void
    {
        $owner = $this->existingOwner();
        [$telegramId, $restore] = $this->bindOwnerTelegram($owner, '910088');

        try {
            $this->fakeBotToken('123456:TEST-TOKEN');

            $this->post('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId),
                'start_param' => 'people',
                'next' => 'https://evil.example/phish',
            ])->assertRedirect('/lavr/people');

            $this->post('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId),
                'start_param' => 'notifications',
            ])->assertRedirect('/lavr/notifications');

            $this->post('/telegram/webapp/session', [
                'init_data' => $this->signInitData('123456:TEST-TOKEN', $telegramId),
                'start_param' => 'reports',
            ])->assertRedirect('/lavr/reports');
        } finally {
            $restore();
        }
    }

    public function test_missing_bot_token_is_unavailable_without_creating_a_user(): void
    {
        $usersBefore = User::query()->count();

        $this->mock(TelegramBotManager::class, function ($mock): void {
            $mock->shouldReceive('existingSetting')->andReturn(null);
        });

        $response = $this->post('/telegram/webapp/session', [
            'init_data' => $this->signInitData('123456:TEST-TOKEN', '910088'),
        ]);

        $response->assertOk();
        $this->assertStringContainsString('"reason":"unavailable"', html_entity_decode($response->getContent()));
        $this->assertGuest();
        $this->assertSame($usersBefore, User::query()->count());
    }

    public function test_session_endpoint_requires_init_data(): void
    {
        $this->from('/telegram/webapp')
            ->post('/telegram/webapp/session', [])
            ->assertRedirect('/telegram/webapp')
            ->assertSessionHasErrors('init_data');
    }

    public function test_browser_login_route_still_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('LAVR');
        $this->get('/register')->assertNotFound();
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($user);

        return $user;
    }

    /**
     * @return array{0: string, 1: callable(): void}
     */
    private function bindOwnerTelegram(User $owner, string $telegramId): array
    {
        $existing = ChannelIdentity::findTelegramForUser((int) $owner->id);
        $created = null;
        $original = null;

        if ($existing !== null) {
            $original = (string) $existing->external_user_id;
            $existing->forceFill([
                'external_user_id' => $telegramId,
                'external_chat_id' => $telegramId,
            ])->save();
        } else {
            $created = $this->createTemporaryTelegramIdentity($owner, $telegramId);
        }

        return [
            $telegramId,
            function () use ($existing, $created, $original, $telegramId): void {
                if ($created !== null) {
                    $this->deleteTelegramIdentity($telegramId);
                }

                if ($existing !== null && $original !== null) {
                    $existing->refresh();
                    $existing->forceFill([
                        'external_user_id' => $original,
                        'external_chat_id' => $original,
                    ])->save();
                }
            },
        ];
    }

    private function fakeBotToken(string $token): void
    {
        $setting = new TelegramBotSetting;
        $setting->bot_token = $token;
        $setting->bot_username = 'lavr_test_bot';

        $this->mock(TelegramBotManager::class, function ($mock) use ($setting): void {
            $mock->shouldReceive('existingSetting')->andReturn($setting);
            $mock->shouldReceive('setting')->andReturn($setting);
        });
    }

    private function signInitData(string $token, string $telegramUserId, ?int $authDate = null): string
    {
        $fields = [
            'auth_date' => (string) ($authDate ?? now()->timestamp),
            'query_id' => 'AAEtest',
            'user' => json_encode([
                'id' => (int) $telegramUserId,
                'first_name' => 'Test',
            ], JSON_UNESCAPED_UNICODE),
        ];
        ksort($fields);
        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key.'='.$value;
        }
        $secret = hash_hmac('sha256', $token, 'WebAppData', true);
        $fields['hash'] = hash_hmac('sha256', $check = implode("\n", $pairs), $secret);

        return http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    }
}
