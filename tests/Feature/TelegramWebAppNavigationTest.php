<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

class TelegramWebAppNavigationTest extends TestCase
{
    public function test_guest_is_redirected_from_mobile_shell_routes(): void
    {
        $this->get('/lavr/today')->assertRedirect(route('login'));
        $this->get('/lavr/people')->assertRedirect(route('login'));
        $this->get('/lavr/more')->assertRedirect(route('login'));
        $this->get('/lavr/projects')->assertRedirect(route('login'));
        $this->get('/lavr/meetings')->assertRedirect(route('login'));
        $this->get('/lavr/commitments')->assertRedirect(route('login'));
    }

    public function test_owner_can_open_today_people_more_and_projects(): void
    {
        $owner = $this->existingOwner();

        $this->actingAs($owner)->get('/lavr/today')->assertOk()->assertSee('Jarvis\\/Today', false);
        $this->actingAs($owner)->get('/lavr/people')->assertOk()->assertSee('Jarvis\\/People', false);
        $this->actingAs($owner)->get('/lavr/more')->assertOk()->assertSee('Jarvis\\/More', false);
        $this->actingAs($owner)->get('/lavr/projects')->assertOk()->assertSee('Jarvis\\/Projects', false);
        $this->actingAs($owner)->get('/lavr/meetings')->assertOk()->assertSee('Jarvis\\/ComingFoundation', false);
        $this->actingAs($owner)->get('/lavr/commitments')->assertOk()->assertSee('Jarvis\\/ComingFoundation', false);
        $this->actingAs($owner)->get('/lavr')->assertRedirect();
        $this->get('/register')->assertNotFound();
    }

    public function test_chat_workspace_still_opens_for_owner(): void
    {
        $owner = $this->existingOwner();
        $response = $this->actingAs($owner)->get('/lavr');
        $response->assertRedirect();
        $this->actingAs($owner)->get($response->headers->get('Location'))->assertOk();
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($user);

        return $user;
    }
}
