<?php

namespace App\Services\Directory;

use App\Models\User;

final class DirectorySearchService
{
    public function __construct(
        private readonly DirectoryService $directory,
    ) {}

    /**
     * @return array{people: list<array<string, mixed>>, organizations: list<array<string, mixed>>, projects: list<array<string, mixed>>}
     */
    public function search(User $user, string $query): array
    {
        $this->directory->assertCanManage($user);

        return [
            'people' => $this->directory->findPeople($user, $query, 8)
                ->map(fn ($person): array => $this->directory->serializePersonSummary($person))
                ->values()
                ->all(),
            'organizations' => $this->directory->findOrganizations($user, $query, 8)
                ->map(fn ($organization): array => $this->directory->serializeOrganization($organization))
                ->values()
                ->all(),
            'projects' => $this->directory->findProjects($user, $query, 8)
                ->map(fn ($project): array => $this->directory->serializeProjectCard($project))
                ->values()
                ->all(),
        ];
    }
}
