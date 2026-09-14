<?php

namespace App\Services\Sources;

use App\Enums\ProjectSourceType;
use App\Enums\SourceBindingKind;
use App\Models\IntegrationAccount;
use App\Models\Project;
use App\Models\ProjectSourceBinding;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Directory\Exceptions\DirectoryException;
use Illuminate\Support\Collection;

final class ProjectSourceBindingService
{
    public function bind(
        User $user,
        Project $project,
        ProjectSourceType $type,
        int $sourceId,
        SourceBindingKind $kind = SourceBindingKind::Explicit,
        ?string $purpose = null,
        ?string $importance = null,
        ?string $monitoringPolicy = null,
    ): ProjectSourceBinding {
        $this->assertOwnsProject($user, $project);
        $this->assertSourceExists($user, $type, $sourceId);

        $existing = ProjectSourceBinding::query()
            ->where('project_id', $project->id)
            ->where('source_type', $type->value)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing !== null && $existing->isExplicit() && $kind === SourceBindingKind::Suggested) {
            return $existing;
        }

        $kindToStore = $existing?->isExplicit() ? SourceBindingKind::Explicit : $kind;

        $binding = ProjectSourceBinding::query()->updateOrCreate(
            [
                'project_id' => $project->id,
                'source_type' => $type->value,
                'source_id' => $sourceId,
            ],
            [
                'binding_kind' => $kindToStore->value,
                'purpose' => $this->nullable($purpose),
                'importance' => $this->nullable($importance),
                'monitoring_policy' => $this->nullable($monitoringPolicy),
            ],
        );

        if ($type === ProjectSourceType::TelegramGroup) {
            $group = TelegramGroup::query()->find($sourceId);
            if ($group !== null && $kindToStore === SourceBindingKind::Explicit) {
                $settings = is_array($group->settings) ? $group->settings : [];
                $settings['monitoring_enabled'] = true;
                $group->forceFill(['settings' => $settings])->save();
            }
        }

        return $binding->fresh() ?? $binding;
    }

    public function unbind(User $user, Project $project, ProjectSourceBinding $binding): void
    {
        $this->assertOwnsProject($user, $project);

        if ((int) $binding->project_id !== (int) $project->id) {
            throw new DirectoryException('not_found');
        }

        $binding->delete();
    }

    /**
     * @return Collection<int, ProjectSourceBinding>
     */
    public function forProject(User $user, Project $project): Collection
    {
        $this->assertOwnsProject($user, $project);

        return ProjectSourceBinding::query()
            ->where('project_id', $project->id)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<ProjectSourceType>  $types
     * @return list<int>
     */
    public function sourceIdsForProject(User $user, int $projectId, array $types): array
    {
        $project = Project::query()->where('user_id', $user->id)->whereKey($projectId)->first();
        if ($project === null) {
            return [];
        }

        $values = array_map(fn (ProjectSourceType $type): string => $type->value, $types);

        return ProjectSourceBinding::query()
            ->where('project_id', $project->id)
            ->whereIn('source_type', $values)
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function projectIdsForSource(User $user, ProjectSourceType $type, int $sourceId, bool $explicitOnly = false): array
    {
        return ProjectSourceBinding::query()
            ->where('source_type', $type->value)
            ->where('source_id', $sourceId)
            ->when($explicitOnly, fn ($query) => $query->where('binding_kind', SourceBindingKind::Explicit->value))
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->pluck('project_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function serializeForProject(User $user, Project $project): array
    {
        return $this->forProject($user, $project)
            ->map(fn (ProjectSourceBinding $binding): array => $this->serialize($user, $binding))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(User $user, ProjectSourceBinding $binding): array
    {
        $identity = $this->sourceIdentity($user, $binding);

        return [
            'id' => $binding->id,
            'source_type' => $binding->source_type->value,
            'source_id' => $binding->source_id,
            'binding_kind' => $binding->binding_kind->value,
            'purpose' => $binding->purpose,
            'label' => $identity['label'],
            'address' => $identity['address'],
            'health' => $identity['health'],
            'last_message_at' => $identity['last_message_at'],
        ];
    }

    /**
     * @return array{label: string, address: ?string, health: ?string, last_message_at: ?string}
     */
    private function sourceIdentity(User $user, ProjectSourceBinding $binding): array
    {
        if (in_array($binding->source_type, [
            ProjectSourceType::GoogleMailbox,
            ProjectSourceType::GoogleCalendar,
            ProjectSourceType::IntegrationAccount,
        ], true)) {
            $account = IntegrationAccount::query()
                ->where('user_id', $user->id)
                ->whereKey($binding->source_id)
                ->first();

            return [
                'label' => $account?->label() ?? 'Google',
                'address' => $account?->external_account_email,
                'health' => $account?->health?->value,
                'last_message_at' => optional($account?->last_event_at)?->toIso8601String(),
            ];
        }

        if ($binding->source_type === ProjectSourceType::TelegramGroup) {
            $group = TelegramGroup::query()->find($binding->source_id);

            return [
                'label' => $group?->title ?: 'Telegram',
                'address' => $group?->username,
                'health' => $group?->status?->value,
                'last_message_at' => optional($group?->last_message_at)?->toIso8601String(),
            ];
        }

        return [
            'label' => $binding->source_type->value,
            'address' => null,
            'health' => null,
            'last_message_at' => null,
        ];
    }

    private function assertOwnsProject(User $user, Project $project): void
    {
        if ((int) $project->user_id !== (int) $user->id) {
            throw new DirectoryException('project_not_found');
        }
    }

    private function assertSourceExists(User $user, ProjectSourceType $type, int $sourceId): void
    {
        $exists = match ($type) {
            ProjectSourceType::GoogleMailbox,
            ProjectSourceType::GoogleCalendar,
            ProjectSourceType::IntegrationAccount => IntegrationAccount::query()
                ->where('user_id', $user->id)
                ->whereKey($sourceId)
                ->exists(),
            ProjectSourceType::TelegramGroup => TelegramGroup::query()->whereKey($sourceId)->exists(),
            default => $sourceId > 0,
        };

        if (! $exists) {
            throw new DirectoryException('not_found');
        }
    }

    private function nullable(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 120) : null;
    }
}
