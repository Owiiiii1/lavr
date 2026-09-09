<?php

namespace Tests\Feature;

use App\Enums\PersonStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\Person;
use App\Models\User;
use App\Services\Directory\DirectoryService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class DirectoryAdminTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_cannot_open_people_admin(): void
    {
        $this->get(route('people.index'))->assertRedirect();
        $this->get(route('organizations.index'))->assertRedirect();
    }

    public function test_regular_user_cannot_open_people_admin(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('people.index'))->assertForbidden();
            $this->actingAs($user)->post(route('people.store'), ['display_name' => 'Nope'])->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_can_create_edit_and_archive_a_person(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $name = 'Admin Person '.Str::random(6);

            $this->actingAs($user)->get(route('people.index'))->assertOk();
            $this->actingAs($user)->post(route('people.store'), [
                'first_name' => 'Sergiy',
                'last_name' => 'Test',
                'display_name' => $name,
                'roles' => ['employee'],
            ])->assertRedirect();

            $person = Person::query()->where('user_id', $user->id)->where('display_name', $name)->first();
            $this->assertNotNull($person);

            $this->actingAs($user)->get(route('people.show', $person))->assertOk();
            $this->actingAs($user)->patch(route('people.update', $person), [
                'display_name' => $name,
                'notes' => 'Ops',
                'roles' => ['employee', 'partner'],
            ])->assertRedirect();
            $this->actingAs($user)->patch(route('people.employee.update', $person), [
                'position' => 'Producer',
                'department' => 'Shows',
            ])->assertRedirect();
            $this->assertSame('Producer', $person->fresh()->employeeProfile?->position);

            $this->actingAs($user)->post(route('people.archive', $person))->assertRedirect();
            $this->assertSame(PersonStatus::Archived, $person->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_can_create_organization_and_attach_project(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $orgName = 'Org '.Str::random(6);
            $this->actingAs($user)->post(route('organizations.store'), [
                'name' => $orgName,
                'type' => 'brand',
            ])->assertRedirect();

            $organization = Organization::query()->where('user_id', $user->id)->where('name', $orgName)->first();
            $this->assertNotNull($organization);
            $this->actingAs($user)->get(route('organizations.show', $organization))->assertOk();

            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(6));
            $this->actingAs($user)->post(route('organizations.projects.store', $organization), [
                'project_id' => $project->id,
                'role' => 'host',
            ])->assertRedirect();
            $this->assertTrue($organization->fresh()->projects()->whereKey($project->id)->exists());

            $person = app(DirectoryService::class)->createPerson($user, ['display_name' => 'Lead '.Str::random(6)]);
            $this->actingAs($user)->post(route('projects.people.store', $project), [
                'person_id' => $person->id,
                'role' => 'producer',
            ])->assertRedirect();
            $this->assertTrue($project->fresh()->people()->whereKey($person->id)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_foreign_person_is_not_found_for_another_owner_like_user(): void
    {
        $owner = null;
        $other = null;

        try {
            $owner = $this->temporaryOwner();
            $other = $this->temporaryOwner();
            $person = app(DirectoryService::class)->createPerson($other, ['display_name' => 'Secret '.Str::random(6)]);

            $this->actingAs($owner)->get(route('people.show', $person))->assertNotFound();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($other);
        }
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
