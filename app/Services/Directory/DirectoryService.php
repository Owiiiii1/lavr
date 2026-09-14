<?php

namespace App\Services\Directory;

use App\Enums\CanonicalEntityType;
use App\Enums\DirectoryPartyType;
use App\Enums\DirectoryRelationType;
use App\Enums\EmploymentStatus;
use App\Enums\OrganizationStatus;
use App\Enums\OwnerLocale;
use App\Enums\PersonIdentityType;
use App\Enums\PersonRoleCode;
use App\Enums\PersonStatus;
use App\Enums\ProjectSourceType;
use App\Enums\ProjectStatus;
use App\Models\DirectoryRelationship;
use App\Models\EmployeeProfile;
use App\Models\KnowledgeEntity;
use App\Models\Organization;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\PersonRole;
use App\Models\Project;
use App\Models\ProjectSourceBinding;
use App\Models\User;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Users\UserCapability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class DirectoryService
{
    public function assertCanManage(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::PEOPLE)) {
            throw new DirectoryException('not_allowed');
        }
    }

    public function ownedPerson(User $user, int $personId): Person
    {
        $this->assertCanManage($user);

        $person = Person::query()
            ->where('user_id', $user->id)
            ->whereKey($personId)
            ->first();

        if ($person === null) {
            throw new DirectoryException('person_not_found');
        }

        return $person;
    }

    public function ownedOrganization(User $user, int $organizationId): Organization
    {
        $this->assertCanManage($user);

        $organization = Organization::query()
            ->where('user_id', $user->id)
            ->whereKey($organizationId)
            ->first();

        if ($organization === null) {
            throw new DirectoryException('organization_not_found');
        }

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPerson(User $user, array $payload): Person
    {
        $this->assertCanManage($user);

        return DB::transaction(function () use ($user, $payload): Person {
            $display = $this->displayName($payload);
            $person = Person::query()->create([
                'user_id' => $user->id,
                'first_name' => $this->nullableString($payload['first_name'] ?? null),
                'last_name' => $this->nullableString($payload['last_name'] ?? null),
                'display_name' => $display,
                'normalized_name' => ProjectNameNormalizer::normalize($display),
                'primary_email' => $this->nullableString($payload['primary_email'] ?? null),
                'primary_phone' => $this->nullableString($payload['primary_phone'] ?? null),
                'telegram_username' => $this->nullableString($payload['telegram_username'] ?? null),
                'notes' => $this->nullableString($payload['notes'] ?? null),
                'preferred_language' => filled($payload['preferred_language'] ?? null)
                    ? OwnerLocale::fromMixed($payload['preferred_language'])->value
                    : null,
                'status' => PersonStatus::from($payload['status'] ?? PersonStatus::Active->value),
            ]);

            $this->syncRoles($person, $payload['roles'] ?? []);
            $this->syncContactIdentities($person);
            $this->logOutcome('directory_person_created', $user, ['person_id' => $person->id]);

            return $person->fresh(['roles', 'identities', 'employeeProfile']) ?? $person;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updatePerson(User $user, Person $person, array $payload): Person
    {
        $this->assertOwnsPerson($user, $person);

        return DB::transaction(function () use ($person, $payload): Person {
            $display = $this->displayName($payload, $person);
            $person->forceFill([
                'first_name' => array_key_exists('first_name', $payload) ? $this->nullableString($payload['first_name']) : $person->first_name,
                'last_name' => array_key_exists('last_name', $payload) ? $this->nullableString($payload['last_name']) : $person->last_name,
                'display_name' => $display,
                'normalized_name' => ProjectNameNormalizer::normalize($display),
                'primary_email' => array_key_exists('primary_email', $payload) ? $this->nullableString($payload['primary_email']) : $person->primary_email,
                'primary_phone' => array_key_exists('primary_phone', $payload) ? $this->nullableString($payload['primary_phone']) : $person->primary_phone,
                'telegram_username' => array_key_exists('telegram_username', $payload) ? $this->nullableString($payload['telegram_username']) : $person->telegram_username,
                'notes' => array_key_exists('notes', $payload) ? $this->nullableString($payload['notes']) : $person->notes,
                'preferred_language' => array_key_exists('preferred_language', $payload)
                    ? (filled($payload['preferred_language'] ?? null)
                        ? OwnerLocale::fromMixed($payload['preferred_language'])->value
                        : null)
                    : $person->preferred_language,
                'status' => isset($payload['status'])
                    ? PersonStatus::from((string) $payload['status'])
                    : $person->status,
            ])->save();

            if (array_key_exists('roles', $payload)) {
                $this->syncRoles($person, $payload['roles'] ?? []);
            }

            $this->syncContactIdentities($person);

            return $person->fresh(['roles', 'identities', 'employeeProfile']) ?? $person;
        });
    }

    public function setPersonStatus(User $user, Person $person, PersonStatus $status): Person
    {
        $this->assertOwnsPerson($user, $person);
        $person->forceFill(['status' => $status])->save();
        $this->logOutcome('directory_person_status', $user, [
            'person_id' => $person->id,
            'status' => $status->value,
        ]);

        return $person->refresh();
    }

    /**
     * @param  list<string>|mixed  $roles
     */
    public function syncRoles(Person $person, mixed $roles): void
    {
        $codes = [];

        foreach (is_array($roles) ? $roles : [] as $role) {
            $code = PersonRoleCode::tryFrom((string) $role);

            if ($code !== null) {
                $codes[$code->value] = $code;
            }
        }

        PersonRole::query()->where('person_id', $person->id)->whereNotIn('role', array_keys($codes))->delete();

        foreach ($codes as $code) {
            PersonRole::query()->firstOrCreate([
                'person_id' => $person->id,
                'role' => $code->value,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertEmployeeProfile(User $user, Person $person, array $payload): EmployeeProfile
    {
        $this->assertOwnsPerson($user, $person);

        if (! $person->roles()->where('role', PersonRoleCode::Employee->value)->exists()) {
            PersonRole::query()->firstOrCreate([
                'person_id' => $person->id,
                'role' => PersonRoleCode::Employee->value,
            ]);
        }

        $managerId = isset($payload['manager_person_id']) ? (int) $payload['manager_person_id'] : null;

        if ($managerId !== null && $managerId > 0) {
            $this->ownedPerson($user, $managerId);
        } else {
            $managerId = null;
        }

        $profile = EmployeeProfile::query()->updateOrCreate(
            ['person_id' => $person->id],
            [
                'position' => $this->nullableString($payload['position'] ?? null),
                'department' => $this->nullableString($payload['department'] ?? null),
                'manager_person_id' => $managerId,
                'employment_status' => EmploymentStatus::from($payload['employment_status'] ?? EmploymentStatus::Active->value),
                'responsibilities' => $this->stringList($payload['responsibilities'] ?? null),
                'areas_of_ownership' => $this->stringList($payload['areas_of_ownership'] ?? null),
                'notes' => $this->nullableString($payload['notes'] ?? null),
            ],
        );

        return $profile->refresh();
    }

    public function addIdentity(
        User $user,
        Person $person,
        PersonIdentityType $type,
        string $value,
        bool $primary = false,
    ): PersonIdentity {
        $this->assertOwnsPerson($user, $person);
        $normalized = IdentityNormalizer::normalize($type, $value);

        if ($normalized === '') {
            throw new DirectoryException('invalid_identity');
        }

        $existing = PersonIdentity::query()
            ->where('type', $type->value)
            ->where('normalized_value', $normalized)
            ->first();

        if ($existing !== null && (int) $existing->person_id !== (int) $person->id) {
            throw new DirectoryException('identity_taken');
        }

        if ($existing !== null) {
            $existing->forceFill([
                'value' => trim($value),
                'is_primary' => $primary || $existing->is_primary,
            ])->save();

            return $existing->refresh();
        }

        if ($primary) {
            PersonIdentity::query()
                ->where('person_id', $person->id)
                ->where('type', $type->value)
                ->update(['is_primary' => false]);
        }

        return PersonIdentity::query()->create([
            'person_id' => $person->id,
            'type' => $type,
            'value' => trim($value),
            'normalized_value' => $normalized,
            'is_primary' => $primary,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createOrganization(User $user, array $payload): Organization
    {
        $this->assertCanManage($user);
        $name = trim((string) ($payload['name'] ?? ''));

        if ($name === '') {
            throw new DirectoryException('invalid_name');
        }

        $organization = Organization::query()->create([
            'user_id' => $user->id,
            'name' => mb_substr($name, 0, 160),
            'normalized_name' => ProjectNameNormalizer::normalize($name),
            'type' => $this->nullableString($payload['type'] ?? null),
            'website' => $this->nullableString($payload['website'] ?? null),
            'email' => $this->nullableString($payload['email'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'notes' => $this->nullableString($payload['notes'] ?? null),
            'status' => OrganizationStatus::from($payload['status'] ?? OrganizationStatus::Active->value),
        ]);

        $this->logOutcome('directory_organization_created', $user, ['organization_id' => $organization->id]);

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateOrganization(User $user, Organization $organization, array $payload): Organization
    {
        $this->assertOwnsOrganization($user, $organization);
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : $organization->name;

        if ($name === '') {
            throw new DirectoryException('invalid_name');
        }

        $organization->forceFill([
            'name' => mb_substr($name, 0, 160),
            'normalized_name' => ProjectNameNormalizer::normalize($name),
            'type' => array_key_exists('type', $payload) ? $this->nullableString($payload['type']) : $organization->type,
            'website' => array_key_exists('website', $payload) ? $this->nullableString($payload['website']) : $organization->website,
            'email' => array_key_exists('email', $payload) ? $this->nullableString($payload['email']) : $organization->email,
            'phone' => array_key_exists('phone', $payload) ? $this->nullableString($payload['phone']) : $organization->phone,
            'notes' => array_key_exists('notes', $payload) ? $this->nullableString($payload['notes']) : $organization->notes,
            'status' => isset($payload['status'])
                ? OrganizationStatus::from((string) $payload['status'])
                : $organization->status,
        ])->save();

        return $organization->refresh();
    }

    public function setOrganizationStatus(User $user, Organization $organization, OrganizationStatus $status): Organization
    {
        $this->assertOwnsOrganization($user, $organization);
        $organization->forceFill(['status' => $status])->save();
        $this->logOutcome('directory_organization_status', $user, [
            'organization_id' => $organization->id,
            'status' => $status->value,
        ]);

        return $organization->refresh();
    }

    public function relate(
        User $user,
        DirectoryPartyType $subjectType,
        int $subjectId,
        DirectoryRelationType $relation,
        DirectoryPartyType $objectType,
        int $objectId,
        ?string $notes = null,
    ): DirectoryRelationship {
        $this->assertCanManage($user);
        $this->assertPartyOwned($user, $subjectType, $subjectId);
        $this->assertPartyOwned($user, $objectType, $objectId);
        $this->assertRelationShape($relation, $subjectType, $objectType);

        if ($subjectType === $objectType && $subjectId === $objectId) {
            throw new DirectoryException('invalid_relationship');
        }

        $row = DirectoryRelationship::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'subject_type' => $subjectType->value,
                'subject_id' => $subjectId,
                'relation_type' => $relation->value,
                'object_type' => $objectType->value,
                'object_id' => $objectId,
            ],
            ['notes' => $this->nullableString($notes)],
        );

        if ($notes !== null) {
            $row->forceFill(['notes' => $this->nullableString($notes)])->save();
        }

        return $row->refresh();
    }

    public function detachRelation(User $user, DirectoryRelationship $relationship): void
    {
        $this->assertCanManage($user);

        if ((int) $relationship->user_id !== (int) $user->id) {
            throw new DirectoryException('not_found');
        }

        $relationship->delete();
    }

    public function attachPersonToProject(User $user, Project $project, Person $person, ?string $role = null, ?string $notes = null): void
    {
        $this->assertOwnsPerson($user, $person);

        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }

        $project->people()->syncWithoutDetaching([
            $person->id => [
                'role' => $this->nullableString($role),
                'notes' => $this->nullableString($notes),
            ],
        ]);
    }

    public function detachPersonFromProject(User $user, Project $project, Person $person): void
    {
        $this->assertOwnsPerson($user, $person);

        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }

        $project->people()->detach($person->id);
    }

    public function attachOrganizationToProject(User $user, Project $project, Organization $organization, ?string $role = null, ?string $notes = null): void
    {
        $this->assertOwnsOrganization($user, $organization);

        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }

        $project->organizations()->syncWithoutDetaching([
            $organization->id => [
                'role' => $this->nullableString($role),
                'notes' => $this->nullableString($notes),
            ],
        ]);
    }

    public function detachOrganizationFromProject(User $user, Project $project, Organization $organization): void
    {
        $this->assertOwnsOrganization($user, $organization);

        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }

        $project->organizations()->detach($organization->id);
    }

    public function bindSource(
        User $user,
        Project $project,
        ProjectSourceType $type,
        int $sourceId,
        ?string $purpose = null,
        ?string $importance = null,
        ?string $monitoringPolicy = null,
    ): ProjectSourceBinding {
        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }

        return ProjectSourceBinding::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'source_type' => $type->value,
                'source_id' => $sourceId,
            ],
            [
                'binding_kind' => 'explicit',
                'purpose' => $this->nullableString($purpose),
                'importance' => $this->nullableString($importance),
                'monitoring_policy' => $this->nullableString($monitoringPolicy),
            ],
        );
    }

    public function linkKnowledge(User $user, KnowledgeEntity $entity, CanonicalEntityType $type, int $canonicalId): KnowledgeEntity
    {
        if ((int) $entity->user_id !== (int) $user->id) {
            throw new DirectoryException('not_found');
        }

        match ($type) {
            CanonicalEntityType::Person => $this->ownedPerson($user, $canonicalId),
            CanonicalEntityType::Organization => $this->ownedOrganization($user, $canonicalId),
            CanonicalEntityType::Project => Project::query()->where('user_id', $user->id)->whereKey($canonicalId)->first()
                ?? throw new DirectoryException('project_not_found'),
        };

        $entity->forceFill([
            'canonical_type' => $type->value,
            'canonical_id' => $canonicalId,
        ])->save();

        $this->logOutcome('directory_knowledge_linked', $user, [
            'knowledge_entity_id' => $entity->id,
            'canonical_type' => $type->value,
            'canonical_id' => $canonicalId,
        ]);

        return $entity->refresh();
    }

    /**
     * @return Collection<int, Person>
     */
    public function listPeople(User $user, ?string $search = null, ?string $role = null, ?int $projectId = null, ?string $status = null): Collection
    {
        $this->assertCanManage($user);

        $query = Person::query()
            ->where('user_id', $user->id)
            ->with(['roles', 'employeeProfile', 'projects.organizations'])
            ->withCount(['projects'])
            ->orderBy('display_name')
            ->orderBy('id');

        $this->applyPersonFilters($query, $search, $role, $projectId, $status);

        return $query->get();
    }

    public function findPeople(User $user, string $query, int $limit = 8): Collection
    {
        $this->assertCanManage($user);
        $needle = ProjectNameNormalizer::normalize($query);

        if ($needle === '') {
            return collect();
        }

        return Person::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $builder) use ($needle, $query): void {
                $builder->where('normalized_name', 'like', '%'.$needle.'%')
                    ->orWhere('display_name', 'like', '%'.$query.'%')
                    ->orWhere('primary_email', 'like', '%'.$needle.'%')
                    ->orWhere('telegram_username', 'like', '%'.$needle.'%')
                    ->orWhereHas('identities', function (Builder $identities) use ($needle): void {
                        $identities->where('normalized_value', 'like', '%'.$needle.'%');
                    });
            })
            ->with(['roles', 'employeeProfile', 'projects'])
            ->orderBy('display_name')
            ->limit($limit)
            ->get();
    }

    public function findOrganizations(User $user, string $query, int $limit = 8): Collection
    {
        $this->assertCanManage($user);
        $needle = ProjectNameNormalizer::normalize($query);

        if ($needle === '') {
            return collect();
        }

        return Organization::query()
            ->where('user_id', $user->id)
            ->where(function (Builder $builder) use ($needle): void {
                $builder->where('normalized_name', 'like', '%'.$needle.'%')
                    ->orWhere('email', 'like', '%'.$needle.'%');
            })
            ->withCount('projects')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    public function findProjects(User $user, string $query, int $limit = 8): Collection
    {
        if (! $user->canUseCapability(UserCapability::PROJECTS)) {
            return collect();
        }

        $needle = ProjectNameNormalizer::normalize($query);

        if ($needle === '') {
            return collect();
        }

        return Project::query()
            ->where('user_id', $user->id)
            ->where('normalized_name', 'like', '%'.$needle.'%')
            ->withCount(['people', 'organizations'])
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function personStatus(User $user, ?int $personId, ?string $name): ?array
    {
        $person = null;

        if ($personId !== null && $personId > 0) {
            $person = Person::query()->where('user_id', $user->id)->whereKey($personId)->first();
        }

        if ($person === null && is_string($name) && trim($name) !== '') {
            $matches = $this->findPeople($user, $name, 5);

            if ($matches->count() === 1) {
                $person = $matches->first();
            } elseif ($matches->count() > 1) {
                return [
                    'source' => 'directory',
                    'error' => 'ambiguous',
                    'candidates' => $matches->map(fn (Person $item): array => $this->serializePersonSummary($item))->values()->all(),
                ];
            }
        }

        if ($person === null) {
            return null;
        }

        return [
            'source' => 'directory',
            ...$this->serializePerson($person->load(['roles', 'identities', 'employeeProfile.manager', 'projects', 'knowledgeEntities'])),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePerson(Person $person): array
    {
        $employee = $person->employeeProfile;
        $primaryOrg = $this->primaryOrganizationName($person);

        return [
            'id' => $person->id,
            'display_name' => $person->display_name,
            'first_name' => $person->first_name,
            'last_name' => $person->last_name,
            'primary_email' => $person->primary_email,
            'primary_phone' => $person->primary_phone,
            'telegram_username' => $person->telegram_username,
            'preferred_language' => $person->preferred_language,
            'notes' => $person->notes,
            'status' => $person->status instanceof PersonStatus ? $person->status->value : (string) $person->status,
            'roles' => $person->roles->map(fn (PersonRole $role): string => $role->role instanceof PersonRoleCode ? $role->role->value : (string) $role->role)->values()->all(),
            'position' => $employee?->position,
            'department' => $employee?->department,
            'employment_status' => $employee?->employment_status instanceof EmploymentStatus
                ? $employee->employment_status->value
                : $employee?->employment_status,
            'responsibilities' => $employee?->responsibilities ?? [],
            'areas_of_ownership' => $employee?->areas_of_ownership ?? [],
            'manager' => $employee?->manager ? [
                'id' => $employee->manager->id,
                'display_name' => $employee->manager->display_name,
            ] : null,
            'primary_organization' => $primaryOrg,
            'organizations' => $this->organizationsFor($person),
            'projects' => $person->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status instanceof ProjectStatus ? $project->status->value : (string) $project->status,
                'role' => $project->pivot->role ?? null,
            ])->values()->all(),
            'identities' => $person->identities->map(fn (PersonIdentity $identity): array => [
                'id' => $identity->id,
                'type' => $identity->type instanceof PersonIdentityType ? $identity->type->value : (string) $identity->type,
                'value' => $identity->value,
                'is_primary' => (bool) $identity->is_primary,
            ])->values()->all(),
            'knowledge_links' => $person->relationLoaded('knowledgeEntities')
                ? $person->knowledgeEntities->map(fn (KnowledgeEntity $entity): array => [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'type' => $entity->type->value,
                ])->values()->all()
                : [],
            'updated_at' => optional($person->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePersonSummary(Person $person): array
    {
        return [
            'id' => $person->id,
            'display_name' => $person->display_name,
            'roles' => $person->roles->map(fn (PersonRole $role): string => $role->role instanceof PersonRoleCode ? $role->role->value : (string) $role->role)->values()->all(),
            'position' => $person->employeeProfile?->position,
            'primary_organization' => $this->primaryOrganizationName($person),
            'status' => $person->status instanceof PersonStatus ? $person->status->value : (string) $person->status,
            'projects' => $person->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
            ])->values()->all(),
            'projects_count' => $person->projects_count ?? $person->projects->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOrganization(Organization $organization): array
    {
        $organization->loadMissing(['projects']);

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'type' => $organization->type,
            'website' => $organization->website,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'notes' => $organization->notes,
            'status' => $organization->status instanceof OrganizationStatus ? $organization->status->value : (string) $organization->status,
            'projects' => $organization->projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'status' => $project->status instanceof ProjectStatus ? $project->status->value : (string) $project->status,
                'role' => $project->pivot->role ?? null,
            ])->values()->all(),
            'projects_count' => $organization->projects_count ?? $organization->projects->count(),
            'updated_at' => optional($organization->updated_at)?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeProjectCard(Project $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'description' => $project->description,
            'category' => $project->category,
            'status' => $project->status instanceof ProjectStatus ? $project->status->value : (string) $project->status,
            'start_date' => optional($project->start_date)?->toDateString(),
            'end_date' => optional($project->end_date)?->toDateString(),
            'people_count' => (int) ($project->people_count ?? $project->people()->count()),
            'organizations_count' => (int) ($project->organizations_count ?? $project->organizations()->count()),
            'updated_at' => optional($project->updated_at)?->toIso8601String(),
        ];
    }

    private function syncContactIdentities(Person $person): void
    {
        if (filled($person->primary_email)) {
            $this->upsertOwnedIdentity($person, PersonIdentityType::Email, (string) $person->primary_email, true);
        }

        if (filled($person->primary_phone)) {
            $this->upsertOwnedIdentity($person, PersonIdentityType::Phone, (string) $person->primary_phone, true);
        }

        if (filled($person->telegram_username)) {
            $this->upsertOwnedIdentity($person, PersonIdentityType::TelegramUsername, (string) $person->telegram_username, true);
        }
    }

    private function upsertOwnedIdentity(Person $person, PersonIdentityType $type, string $value, bool $primary): void
    {
        $normalized = IdentityNormalizer::normalize($type, $value);

        if ($normalized === '') {
            return;
        }

        $taken = PersonIdentity::query()
            ->where('type', $type->value)
            ->where('normalized_value', $normalized)
            ->where('person_id', '!=', $person->id)
            ->exists();

        if ($taken) {
            throw new DirectoryException('identity_taken');
        }

        PersonIdentity::query()->updateOrCreate(
            [
                'person_id' => $person->id,
                'type' => $type->value,
                'normalized_value' => $normalized,
            ],
            [
                'value' => trim($value),
                'is_primary' => $primary,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displayName(array $payload, ?Person $person = null): string
    {
        $explicit = trim((string) ($payload['display_name'] ?? ''));

        if ($explicit !== '') {
            return mb_substr($explicit, 0, 160);
        }

        $first = trim((string) ($payload['first_name'] ?? $person?->first_name ?? ''));
        $last = trim((string) ($payload['last_name'] ?? $person?->last_name ?? ''));
        $combined = trim($first.' '.$last);

        if ($combined !== '') {
            return mb_substr($combined, 0, 160);
        }

        if ($person !== null) {
            return $person->display_name;
        }

        throw new DirectoryException('invalid_name');
    }

    private function applyPersonFilters(Builder $query, ?string $search, ?string $role, ?int $projectId, ?string $status): void
    {
        if (is_string($search) && trim($search) !== '') {
            $needle = ProjectNameNormalizer::normalize($search);
            $query->where(function (Builder $builder) use ($needle): void {
                $builder->where('normalized_name', 'like', '%'.$needle.'%')
                    ->orWhere('primary_email', 'like', '%'.$needle.'%')
                    ->orWhere('telegram_username', 'like', '%'.$needle.'%');
            });
        }

        if (is_string($role) && PersonRoleCode::tryFrom($role) !== null) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('role', $role));
        }

        if ($projectId !== null && $projectId > 0) {
            $query->whereHas('projects', fn (Builder $projects) => $projects->where('projects.id', $projectId));
        }

        if (is_string($status) && PersonStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function organizationsFor(Person $person): array
    {
        $person->loadMissing('projects.organizations');

        $relatedIds = DirectoryRelationship::query()
            ->where('user_id', $person->user_id)
            ->where('subject_type', DirectoryPartyType::Person->value)
            ->where('subject_id', $person->id)
            ->where('object_type', DirectoryPartyType::Organization->value)
            ->pluck('object_id');

        $fromRelations = $relatedIds->isEmpty()
            ? collect()
            : Organization::query()->whereIn('id', $relatedIds)->get();

        return $fromRelations
            ->concat($person->projects->flatMap(fn (Project $project) => $project->organizations))
            ->unique('id')
            ->values()
            ->map(fn (Organization $organization): array => [
                'id' => $organization->id,
                'name' => $organization->name,
                'status' => $organization->status instanceof OrganizationStatus
                    ? $organization->status->value
                    : (string) $organization->status,
            ])
            ->all();
    }

    private function primaryOrganizationName(Person $person): ?string
    {
        $worksFor = DirectoryRelationship::query()
            ->where('user_id', $person->user_id)
            ->where('subject_type', DirectoryPartyType::Person->value)
            ->where('subject_id', $person->id)
            ->where('relation_type', DirectoryRelationType::WorksFor->value)
            ->where('object_type', DirectoryPartyType::Organization->value)
            ->first();

        if ($worksFor !== null) {
            return Organization::query()->whereKey($worksFor->object_id)->value('name');
        }

        $person->loadMissing('projects.organizations');

        return $person->projects->first()?->organizations->first()?->name;
    }

    private function assertOwnsPerson(User $user, Person $person): void
    {
        $this->assertCanManage($user);

        if ((int) $person->user_id !== (int) $user->id) {
            throw new DirectoryException('person_not_found');
        }
    }

    private function assertOwnsOrganization(User $user, Organization $organization): void
    {
        $this->assertCanManage($user);

        if ((int) $organization->user_id !== (int) $user->id) {
            throw new DirectoryException('organization_not_found');
        }
    }

    private function assertPartyOwned(User $user, DirectoryPartyType $type, int $id): void
    {
        if ($type === DirectoryPartyType::Person) {
            $this->ownedPerson($user, $id);

            return;
        }

        $this->ownedOrganization($user, $id);
    }

    private function assertRelationShape(DirectoryRelationType $relation, DirectoryPartyType $subject, DirectoryPartyType $object): void
    {
        $allowed = match ($relation) {
            DirectoryRelationType::WorksFor, DirectoryRelationType::ContractorFor, DirectoryRelationType::Represents, DirectoryRelationType::ClientOf => $subject === DirectoryPartyType::Person && $object === DirectoryPartyType::Organization,
            DirectoryRelationType::Manages, DirectoryRelationType::ReportsTo => $subject === DirectoryPartyType::Person && $object === DirectoryPartyType::Person,
            DirectoryRelationType::AdvisorTo => $subject === DirectoryPartyType::Person,
            DirectoryRelationType::PartnerOf, DirectoryRelationType::CollaboratesWith, DirectoryRelationType::Other => true,
        };

        if (! $allowed) {
            throw new DirectoryException('invalid_relationship');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (is_string($value) && trim($value) !== '') {
            return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n|,/', $value) ?: [])));
        }

        if (! is_array($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            }
        }

        return $items;
    }

    /**
     * @param  array<string, int|string>  $context
     */
    private function logOutcome(string $event, User $user, array $context): void
    {
        Log::info($event, [
            'user_id' => $user->id,
            ...$context,
        ]);
    }
}
