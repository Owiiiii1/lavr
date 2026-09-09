<?php

namespace Tests\Feature;

use App\Enums\DirectoryPartyType;
use App\Enums\DirectoryRelationType;
use App\Enums\PersonIdentityType;
use App\Enums\PersonRoleCode;
use App\Enums\PersonStatus;
use App\Enums\UserRole;
use App\Models\DirectoryRelationship;
use App\Models\EmployeeProfile;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\User;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Directory\PersonMergeService;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class DirectoryPersonTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_directory_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('people'));
        $this->assertTrue(Schema::hasTable('person_roles'));
        $this->assertTrue(Schema::hasTable('person_identities'));
        $this->assertTrue(Schema::hasTable('employee_profiles'));
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('directory_relationships'));
        $this->assertTrue(Schema::hasTable('project_people'));
        $this->assertTrue(Schema::hasTable('project_organizations'));
        $this->assertTrue(Schema::hasTable('project_source_bindings'));
        $this->assertTrue(Schema::hasColumns('projects', ['category', 'start_date', 'end_date', 'owner_person_id']));
        $this->assertTrue(Schema::hasColumns('knowledge_entities', ['canonical_type', 'canonical_id']));
    }

    public function test_person_can_have_multiple_roles_and_an_employee_profile(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, [
                'first_name' => 'Sonia',
                'last_name' => 'Test',
                'primary_email' => 'sonia-'.Str::lower(Str::random(8)).'@invalid.local',
                'roles' => ['employee', 'advisor'],
            ]);

            $this->assertSame(['advisor', 'employee'], $person->roles->pluck('role')->map->value->sort()->values()->all());

            $profile = $directory->upsertEmployeeProfile($user, $person, [
                'position' => 'Producer',
                'department' => 'Shows',
                'responsibilities' => "Chicago\nCasting",
            ]);

            $this->assertInstanceOf(EmployeeProfile::class, $profile);
            $this->assertSame('Producer', $profile->position);
            $this->assertSame(['Chicago', 'Casting'], $profile->responsibilities);
            $this->assertTrue($person->roles()->where('role', PersonRoleCode::Employee->value)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_identity_normalized_pair_is_unique_and_email_is_not_unique_on_people(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $email = 'shared-'.Str::lower(Str::random(8)).'@invalid.local';
            $first = $directory->createPerson($user, [
                'display_name' => 'First '.Str::random(6),
                'primary_email' => $email,
            ]);
            $second = $directory->createPerson($user, [
                'display_name' => 'Second '.Str::random(6),
            ]);

            $this->assertNotSame($first->primary_email, $second->primary_email);

            try {
                $directory->addIdentity($user, $second, PersonIdentityType::Email, $email);
                $this->fail('Duplicate identity should fail.');
            } catch (DirectoryException $exception) {
                $this->assertSame('identity_taken', $exception->error);
            }

            $this->assertSame(1, PersonIdentity::query()->where('type', 'email')->where('normalized_value', $email)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_relationships_and_project_memberships_are_structured(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, ['display_name' => 'Marco '.Str::random(6), 'roles' => ['employee']]);
            $organization = $directory->createOrganization($user, ['name' => 'YFS '.Str::random(6)]);
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(6));

            $directory->relate(
                $user,
                DirectoryPartyType::Person,
                $person->id,
                DirectoryRelationType::WorksFor,
                DirectoryPartyType::Organization,
                $organization->id,
            );
            $directory->attachPersonToProject($user, $project, $person, 'lead');
            $directory->attachOrganizationToProject($user, $project, $organization, 'host');

            $this->assertSame(1, DirectoryRelationship::query()->where('subject_id', $person->id)->count());
            $this->assertTrue($project->people()->whereKey($person->id)->exists());
            $this->assertTrue($project->organizations()->whereKey($organization->id)->exists());

            try {
                $directory->relate(
                    $user,
                    DirectoryPartyType::Person,
                    $person->id,
                    DirectoryRelationType::WorksFor,
                    DirectoryPartyType::Person,
                    $person->id,
                );
                $this->fail('Invalid relationship shape should fail.');
            } catch (DirectoryException $exception) {
                $this->assertSame('invalid_relationship', $exception->error);
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_merge_moves_identities_and_archives_the_source(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $target = $directory->createPerson($user, ['display_name' => 'Canonical '.Str::random(6)]);
            $source = $directory->createPerson($user, [
                'display_name' => 'Duplicate '.Str::random(6),
                'primary_email' => 'dup-'.Str::lower(Str::random(8)).'@invalid.local',
                'roles' => ['client'],
            ]);
            $project = app(ProjectService::class)->create($user, 'Miami '.Str::random(6));
            $directory->attachPersonToProject($user, $project, $source);

            app(PersonMergeService::class)->merge($user, $target, $source);

            $this->assertSame(PersonStatus::Archived, $source->fresh()->status);
            $this->assertTrue($target->fresh()->projects()->whereKey($project->id)->exists());
            $this->assertTrue($target->roles()->where('role', PersonRoleCode::Client->value)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_archive_does_not_hard_delete_a_person(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, ['display_name' => 'Archive '.Str::random(6)]);
            $directory->setPersonStatus($user, $person, PersonStatus::Archived);

            $this->assertTrue(Person::query()->whereKey($person->id)->exists());
            $this->assertSame(PersonStatus::Archived, $person->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
