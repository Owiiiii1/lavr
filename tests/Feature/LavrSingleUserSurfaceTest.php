<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class LavrSingleUserSurfaceTest extends TestCase
{
    public function test_public_registration_is_not_routed(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }

    public function test_user_management_and_impersonation_are_not_routed(): void
    {
        $owner = $this->existingOwner();

        $this->assertFalse(Route::has('settings.users.store'));
        $this->assertFalse(Route::has('settings.users.show'));
        $this->assertFalse(Route::has('settings.users.impersonate'));
        $this->assertFalse(Route::has('impersonation.stop'));

        $this->actingAs($owner)->post('/settings/users')->assertNotFound();
        $this->actingAs($owner)->get('/settings/users/1')->assertNotFound();
        $this->actingAs($owner)->post('/impersonation/stop')->assertNotFound();
    }

    public function test_login_page_is_single_user_and_branded_lavr(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('LAVR')
            ->assertDontSee('Sign in to Jarvis')
            ->assertDontSee('Вход в Jarvis')
            ->assertDontSee('Create account')
            ->assertDontSee('Register');
    }

    public function test_canonical_workspace_is_lavr_and_legacy_paths_redirect(): void
    {
        $this->assertSame('/lavr', route('jarvis.index', absolute: false));
        $this->assertFalse(Route::has('chat.index'));

        $this->get('/chat')->assertRedirect('/lavr');
        $this->get('/jarvis')->assertRedirect('/lavr');
        $this->get('/chat/chats/1')->assertRedirect('/lavr/chats/1');
        $this->get('/jarvis/chats/1')->assertRedirect('/lavr/chats/1');
        $this->post('/chat/chats')->assertStatus(405);
    }

    public function test_guest_cannot_open_legacy_or_canonical_workspace(): void
    {
        $this->get('/lavr')->assertRedirect(route('login'));
        $this->followingRedirects()->get('/chat')->assertOk()->assertSee('LAVR');
    }

    public function test_settings_does_not_expose_users_catalog(): void
    {
        $owner = $this->existingOwner();

        $this->actingAs($owner)
            ->get('/settings')
            ->assertOk()
            ->assertDontSee('Add user')
            ->assertDontSee('UsersPanel');
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();

        $this->assertNotNull($user, 'LAVR surface tests require an existing owner user.');

        return $user;
    }
}
