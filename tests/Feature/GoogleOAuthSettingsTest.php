<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GoogleOAuthSetting;
use App\Models\User;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\Google\GoogleOAuthSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\RestoresGoogleOAuthSettings;
use Tests\TestCase;

class GoogleOAuthSettingsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresGoogleOAuthSettings;

    public function test_owner_can_save_google_oauth_configuration_without_exposing_the_secret(): void
    {
        $owner = null;
        $secret = 'synthetic-google-admin-secret-'.bin2hex(random_bytes(6));

        try {
            $this->snapshotGoogleOAuthSettings();
            GoogleOAuthSetting::query()->delete();
            config([
                'app.url' => 'https://jarvis.example.test',
                'integrations.google.client_id' => '',
                'integrations.google.client_secret' => '',
                'integrations.google.redirect_uri' => '',
            ]);
            $owner = $this->temporaryOwner();

            $this->actingAs($owner)->post(route('settings.integrations.google.update'), [
                'client_id' => 'admin-google-client-id.apps.googleusercontent.com',
                'client_secret' => $secret,
                'redirect_uri' => '',
            ])->assertRedirect();

            $oauth = app(GoogleOAuthService::class);
            $settings = app(GoogleOAuthSettingsService::class);
            $this->assertTrue($oauth->isConfigured());
            $this->assertSame('admin-google-client-id.apps.googleusercontent.com', $settings->clientId());
            $this->assertSame('https://jarvis.example.test/integrations/google/callback', $oauth->redirectUri());

            $raw = DB::table('google_oauth_settings')->value('client_secret');
            $this->assertIsString($raw);
            $this->assertStringNotContainsString($secret, (string) $raw);

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'google']));
            $page->assertOk();
            $html = $page->getContent();
            $this->assertStringContainsString('"tab":"google"', $html);
            $this->assertStringContainsString('"oauth_client_label":"Configured"', $html);
            $this->assertStringContainsString('"account_status_label":"Not connected"', $html);
            $this->assertStringContainsString('"has_client_secret":true', $html);
            $this->assertStringContainsString('"client_secret_source":"admin"', $html);
            $this->assertStringNotContainsString($secret, $html);
            $this->assertStringNotContainsString('"client_secret"', $html);
        } finally {
            $this->restoreGoogleOAuthSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_empty_secret_on_save_keeps_the_stored_secret(): void
    {
        $owner = null;
        $secret = 'synthetic-google-keep-secret-'.bin2hex(random_bytes(6));

        try {
            $this->snapshotGoogleOAuthSettings();
            GoogleOAuthSetting::query()->delete();
            config([
                'integrations.google.client_id' => '',
                'integrations.google.client_secret' => '',
            ]);
            $owner = $this->temporaryOwner();

            $this->actingAs($owner)->post(route('settings.integrations.google.update'), [
                'client_id' => 'keep-client-id',
                'client_secret' => $secret,
            ])->assertRedirect();

            $this->actingAs($owner)->post(route('settings.integrations.google.update'), [
                'client_id' => 'keep-client-id',
                'client_secret' => '',
            ])->assertRedirect();

            $this->assertSame($secret, app(GoogleOAuthSettingsService::class)->clientSecret());
        } finally {
            $this->restoreGoogleOAuthSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_database_settings_override_env_fallback(): void
    {
        $owner = null;

        try {
            $this->snapshotGoogleOAuthSettings();
            GoogleOAuthSetting::query()->delete();
            config([
                'app.url' => 'https://jarvis.example.test',
                'integrations.google.client_id' => 'env-google-client-id',
                'integrations.google.client_secret' => 'env-google-client-secret',
                'integrations.google.redirect_uri' => 'https://env.example.test/integrations/google/callback',
            ]);

            $service = app(GoogleOAuthSettingsService::class);
            $this->assertSame('env-google-client-id', $service->clientId());
            $this->assertSame('env-google-client-secret', $service->clientSecret());
            $this->assertSame('https://env.example.test/integrations/google/callback', $service->redirectUri());
            $this->assertSame('env', $service->clientSecretSource());

            $owner = $this->temporaryOwner();
            $this->actingAs($owner)->post(route('settings.integrations.google.update'), [
                'client_id' => 'db-google-client-id',
                'client_secret' => 'db-google-client-secret',
                'redirect_uri' => 'https://db.example.test/integrations/google/callback',
            ])->assertRedirect();

            $this->assertSame('db-google-client-id', $service->clientId());
            $this->assertSame('db-google-client-secret', $service->clientSecret());
            $this->assertSame('https://db.example.test/integrations/google/callback', $service->redirectUri());
            $this->assertSame('admin', $service->clientSecretSource());
        } finally {
            $this->restoreGoogleOAuthSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_http_redirect_uri_is_rejected_except_localhost(): void
    {
        $owner = null;

        try {
            $this->snapshotGoogleOAuthSettings();
            $owner = $this->temporaryOwner();

            $this->actingAs($owner)->from(route('settings.index', ['tab' => 'integrations']))
                ->post(route('settings.integrations.google.update'), [
                    'client_id' => 'client-id',
                    'client_secret' => 'client-secret-value',
                    'redirect_uri' => 'http://evil.example.test/callback',
                ])
                ->assertRedirect(route('settings.index', ['tab' => 'integrations']))
                ->assertSessionHasErrors('redirect_uri');
        } finally {
            $this->restoreGoogleOAuthSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_ordinary_user_and_guest_cannot_save_google_oauth_configuration(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();

            $this->actingAs($user)->post(route('settings.integrations.google.update'), [
                'client_id' => 'stolen-client-id',
                'client_secret' => 'stolen-client-secret',
            ])->assertForbidden();

            auth()->logout();
            $this->post(route('settings.integrations.google.update'), [
                'client_id' => 'stolen-client-id',
                'client_secret' => 'stolen-client-secret',
            ])->assertRedirect(route('login'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_opening_integrations_does_not_copy_env_secret_into_the_database(): void
    {
        $owner = null;

        try {
            $this->snapshotGoogleOAuthSettings();
            GoogleOAuthSetting::query()->delete();
            config([
                'integrations.google.client_id' => 'env-only-client-id',
                'integrations.google.client_secret' => 'env-only-client-secret',
            ]);
            $owner = $this->temporaryOwner();

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'google']));
            $page->assertOk();
            $this->assertStringContainsString('"client_id_source":"env"', $page->getContent());
            $this->assertStringContainsString('"client_secret_source":"env"', $page->getContent());
            $this->assertStringNotContainsString('env-only-client-secret', $page->getContent());
            $this->assertSame(0, GoogleOAuthSetting::query()->count());
        } finally {
            $this->restoreGoogleOAuthSettings();
            $this->deleteTemporaryUser($owner);
        }
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
