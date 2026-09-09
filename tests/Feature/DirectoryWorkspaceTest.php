<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Directory\DirectoryService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class DirectoryWorkspaceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_is_redirected_from_directory_workspace_routes(): void
    {
        $this->get('/lavr/people')->assertRedirect(route('login'));
        $this->get('/lavr/organizations')->assertRedirect(route('login'));
        $this->get('/lavr/search')->assertRedirect(route('login'));
    }

    public function test_regular_user_cannot_open_people_workspace(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('jarvis.people.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_can_open_people_person_organization_and_project_pages(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, [
                'display_name' => 'Sonia '.Str::random(6),
                'roles' => ['employee'],
            ]);
            $organization = $directory->createOrganization($user, ['name' => 'Studio '.Str::random(6)]);
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(6));
            $directory->attachPersonToProject($user, $project, $person);
            $directory->attachOrganizationToProject($user, $project, $organization);

            $people = $this->inertia($this->actingAs($user)->get(route('jarvis.people.index')));
            $this->assertSame('Jarvis/People', $people['component']);
            $this->assertSame('uk', $people['props']['locale']);

            $card = $this->inertia($this->actingAs($user)->get(route('jarvis.people.show', $person)));
            $this->assertSame('Jarvis/PersonShow', $card['component']);
            $this->assertSame($person->display_name, $card['props']['person']['display_name']);

            $orgs = $this->inertia($this->actingAs($user)->get(route('jarvis.organizations.index')));
            $this->assertSame('Jarvis/Organizations', $orgs['component']);
            $this->inertia($this->actingAs($user)->get(route('jarvis.organizations.show', $organization)));

            $projectPage = $this->inertia($this->actingAs($user)->get(route('jarvis.workspace.projects.show', $project)));
            $this->assertSame($person->id, $projectPage['props']['project']['people'][0]['id']);
            $this->assertSame($organization->id, $projectPage['props']['project']['organizations'][0]['id']);

            $search = $this->inertia($this->actingAs($user)->get(route('jarvis.search.show', ['q' => $person->display_name])));
            $this->assertSame($person->id, $search['props']['results']['people'][0]['id']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_foreign_person_is_hidden_with_404(): void
    {
        $owner = null;
        $other = null;

        try {
            $owner = $this->temporaryOwner();
            $other = $this->temporaryOwner();
            $person = app(DirectoryService::class)->createPerson($other, ['display_name' => 'Hidden '.Str::random(6)]);
            $this->actingAs($owner)->get(route('jarvis.people.show', $person))->assertNotFound();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_people_workspace_copy_follows_uk_en_ru(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $profile = app(AssistantProfileService::class)->profileFor($user, persist: true);

            $uk = $this->inertia($this->actingAs($user)->get(route('jarvis.people.index')));
            $this->assertSame('uk', $uk['props']['locale']);
            $this->assertSame('Jarvis/People', $uk['component']);

            $profile->forceFill(['interface_locale' => 'en', 'assistant_locale' => 'uk'])->save();
            $en = $this->inertia($this->actingAs($user)->get(route('jarvis.people.index')));
            $this->assertSame('en', $en['props']['locale']);

            $profile->forceFill(['interface_locale' => 'ru'])->save();
            $ru = $this->inertia($this->actingAs($user)->get(route('jarvis.people.index')));
            $this->assertSame('ru', $ru['props']['locale']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inertia(TestResponse $response): array
    {
        $response->assertOk();
        $html = $response->getContent();
        $marker = '<script data-page="app" type="application/json">';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start);
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        $this->assertNotFalse($end);
        $page = json_decode(substr($html, $start, $end - $start), true);
        $this->assertIsArray($page);

        return $page;
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
