<?php

namespace App\Services\Sources;

use App\Enums\ProjectSourceType;
use App\Enums\SourceBindingKind;
use App\Models\Person;
use App\Models\Project;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\Projects\ProjectNameNormalizer;

final class SourceRouter
{
    public function __construct(
        private readonly ProjectSourceBindingService $bindings,
    ) {}

    /**
     * @param  array{
     *     source_type?: ProjectSourceType|string|null,
     *     source_id?: int|null,
     *     thread_id?: string|null,
     *     person_id?: int|null,
     *     organization_id?: int|null,
     *     title?: string|null,
     *     metadata?: array<string, mixed>
     * }  $signals
     * @return array{project_id: ?int, confidence: string, reason: string}
     */
    public function resolveProject(User $user, array $signals): array
    {
        $sourceType = $this->sourceType($signals['source_type'] ?? null);
        $sourceId = isset($signals['source_id']) ? (int) $signals['source_id'] : 0;

        if ($sourceType !== null && $sourceId > 0) {
            $explicit = $this->bindings->projectIdsForSource($user, $sourceType, $sourceId, explicitOnly: true);
            if (count($explicit) === 1) {
                return ['project_id' => $explicit[0], 'confidence' => 'high', 'reason' => 'explicit_source_binding'];
            }
            if (count($explicit) > 1) {
                return ['project_id' => null, 'confidence' => 'unresolved', 'reason' => 'shared_source'];
            }

            $suggested = $this->bindings->projectIdsForSource($user, $sourceType, $sourceId, explicitOnly: false);
            $suggestedOnly = array_values(array_diff($suggested, $explicit));
            if (count($suggestedOnly) === 1) {
                return ['project_id' => $suggestedOnly[0], 'confidence' => 'medium', 'reason' => 'suggested_source_binding'];
            }
        }

        $threadId = trim((string) ($signals['thread_id'] ?? ''));
        if ($threadId !== '') {
            $fromThread = $this->projectFromThread($user, $threadId);
            if ($fromThread !== null) {
                return ['project_id' => $fromThread, 'confidence' => 'high', 'reason' => 'thread_binding'];
            }
        }

        $personId = isset($signals['person_id']) ? (int) $signals['person_id'] : 0;
        if ($personId > 0) {
            $personProjects = $this->projectsForPerson($user, $personId);
            if (count($personProjects) === 1) {
                return ['project_id' => $personProjects[0], 'confidence' => 'medium', 'reason' => 'person_project'];
            }
        }

        $title = trim((string) ($signals['title'] ?? ''));
        if ($title !== '') {
            $match = $this->strongNameMatch($user, $title);
            if ($match !== null) {
                return ['project_id' => $match, 'confidence' => 'medium', 'reason' => 'deterministic_metadata'];
            }
        }

        return ['project_id' => null, 'confidence' => 'unresolved', 'reason' => 'unresolved'];
    }

    public function suggestBinding(User $user, ProjectSourceType $type, int $sourceId, string $label): ?array
    {
        $existing = $this->bindings->projectIdsForSource($user, $type, $sourceId);
        if ($existing !== []) {
            return null;
        }

        $match = $this->strongNameMatch($user, $label);
        if ($match === null) {
            return null;
        }

        return [
            'project_id' => $match,
            'source_type' => $type->value,
            'source_id' => $sourceId,
            'binding_kind' => SourceBindingKind::Suggested->value,
            'reason' => 'label_match',
        ];
    }

    private function sourceType(mixed $value): ?ProjectSourceType
    {
        if ($value instanceof ProjectSourceType) {
            return $value;
        }

        return is_string($value) ? ProjectSourceType::tryFrom($value) : null;
    }

    private function projectFromThread(User $user, string $threadId): ?int
    {
        $item = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('thread_id', $threadId)
            ->whereNotNull('project_id')
            ->orderByDesc('id')
            ->first();

        return $item?->project_id !== null ? (int) $item->project_id : null;
    }

    /**
     * @return list<int>
     */
    private function projectsForPerson(User $user, int $personId): array
    {
        $person = Person::query()->where('user_id', $user->id)->whereKey($personId)->first();
        if ($person === null) {
            return [];
        }

        return $person->projects()->pluck('projects.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function strongNameMatch(User $user, string $haystack): ?int
    {
        $normalizedHaystack = ProjectNameNormalizer::normalize($haystack);
        if ($normalizedHaystack === '') {
            return null;
        }

        $matches = [];
        foreach (Project::query()->where('user_id', $user->id)->get(['id', 'name', 'normalized_name']) as $project) {
            $needle = (string) ($project->normalized_name ?: ProjectNameNormalizer::normalize((string) $project->name));
            if ($needle !== '' && str_contains($normalizedHaystack, $needle)) {
                $matches[] = (int) $project->id;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
