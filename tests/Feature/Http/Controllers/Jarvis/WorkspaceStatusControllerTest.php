<?php

namespace Tests\Feature\Http\Controllers\Jarvis;

use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class WorkspaceStatusControllerTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_is_redirected_from_workspace_status(): void
    {
        $this->get(route('jarvis.workspace.status'))->assertRedirect(route('login'));
        $this->get(route('jarvis.workspace.status'))->assertRedirect(route('login'));
    }

    public function test_user_receives_lightweight_counts_without_secrets(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();

            $response = $this->actingAs($user)->getJson(route('jarvis.workspace.status'));

            $response->assertOk();
            $response->assertJsonPath('tasks.active_count', 0);
            $response->assertJsonPath('reminders.active_count', 0);
            $response->assertJsonPath('reports.active_count', 0);
            $response->assertJsonPath('notifications.unread_count', 0);
            $this->assertArrayNotHasKey('access_code', $response->json('telegram') ?? []);
            $this->assertArrayNotHasKey('elevenlabs_api_key', $response->json() ?? []);
            $this->assertArrayNotHasKey('google_refresh_token', $response->json() ?? []);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
