<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Meeting;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Zoom\ZoomCredentialService;
use Illuminate\Support\Facades\Http;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ZoomIntegrationSettingsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_owner_can_save_zoom_credentials_without_exposing_secrets(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $user = $this->createTemporaryUser();

            $this->actingAs($owner)->post(route('settings.integrations.zoom.update'), [
                'account_id' => 'acct-1',
                'client_id' => 'client-1',
                'client_secret' => 'super-secret-zoom',
                'webhook_secret' => 'webhook-secret-zoom',
                'enabled' => 1,
            ])->assertRedirect();

            $page = $this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations']));
            $page->assertOk();
            $html = $page->getContent();
            $this->assertStringContainsString('Zoom', $html);
            $this->assertStringContainsString('acct-1', $html);
            $this->assertStringNotContainsString('super-secret-zoom', $html);
            $this->assertStringNotContainsString('webhook-secret-zoom', $html);

            $payload = app(ZoomCredentialService::class)->adminPayload($owner);
            $this->assertTrue($payload['has_client_secret']);
            $this->assertArrayNotHasKey('client_secret', $payload);

            $this->actingAs($user)->post(route('settings.integrations.zoom.update'), [
                'account_id' => 'acct-2',
            ])->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_test_connection_does_not_create_a_meeting(): void
    {
        $owner = null;

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://zoom.us/oauth/token' => Http::response([
                    'access_token' => 'zoom-access-token',
                    'expires_in' => 3600,
                ], 200),
                'https://api.zoom.us/v2/users/me' => Http::response(['id' => 'me'], 200),
            ]);
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            app(ZoomCredentialService::class)->save($owner, [
                'account_id' => 'acct-1',
                'client_id' => 'client-1',
                'client_secret' => 'secret-1',
                'webhook_secret' => 'wh-1',
                'enabled' => true,
            ]);

            $this->actingAs($owner)->post(route('settings.integrations.zoom.test'))->assertRedirect();
            $this->assertSame(0, Meeting::query()->where('user_id', $owner->id)->count());
            $this->assertContains('zoom', array_map(
                static fn ($provider): string => $provider->key(),
                app(IntegrationRegistry::class)->all(),
            ));
        } finally {
            $this->deleteTemporaryUser($owner);
        }
    }
}
