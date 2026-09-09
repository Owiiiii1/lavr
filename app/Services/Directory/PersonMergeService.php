<?php

namespace App\Services\Directory;

use App\Enums\PersonStatus;
use App\Models\DirectoryRelationship;
use App\Models\EmployeeProfile;
use App\Models\KnowledgeEntity;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\PersonRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Directory\Exceptions\DirectoryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PersonMergeService
{
    public function __construct(
        private readonly DirectoryService $directory,
    ) {}

    public function merge(User $user, Person $target, Person $source): Person
    {
        $this->directory->assertCanManage($user);

        if ((int) $target->user_id !== (int) $user->id || (int) $source->user_id !== (int) $user->id) {
            throw new DirectoryException('person_not_found');
        }

        if ((int) $target->id === (int) $source->id) {
            throw new DirectoryException('invalid_merge');
        }

        return DB::transaction(function () use ($user, $target, $source): Person {
            $source->load(['roles', 'identities', 'projects']);

            foreach ($source->roles as $role) {
                PersonRole::query()->firstOrCreate([
                    'person_id' => $target->id,
                    'role' => $role->role->value,
                ]);
            }

            foreach ($source->identities as $identity) {
                $taken = PersonIdentity::query()
                    ->where('type', $identity->type->value)
                    ->where('normalized_value', $identity->normalized_value)
                    ->where('person_id', '!=', $source->id)
                    ->exists();

                if ($taken) {
                    $identity->delete();

                    continue;
                }

                $identity->forceFill(['person_id' => $target->id])->save();
            }

            $sourceProfile = EmployeeProfile::query()->where('person_id', $source->id)->first();
            $targetProfile = EmployeeProfile::query()->where('person_id', $target->id)->first();

            if ($sourceProfile !== null && $targetProfile === null) {
                $sourceProfile->forceFill(['person_id' => $target->id])->save();
            } elseif ($sourceProfile !== null) {
                $sourceProfile->delete();
            }

            DirectoryRelationship::query()
                ->where('user_id', $user->id)
                ->where('subject_type', 'person')
                ->where('subject_id', $source->id)
                ->update(['subject_id' => $target->id]);

            DirectoryRelationship::query()
                ->where('user_id', $user->id)
                ->where('object_type', 'person')
                ->where('object_id', $source->id)
                ->update(['object_id' => $target->id]);

            DirectoryRelationship::query()
                ->where('user_id', $user->id)
                ->where('subject_type', 'person')
                ->where('object_type', 'person')
                ->whereColumn('subject_id', 'object_id')
                ->delete();

            foreach ($source->projects as $project) {
                $target->projects()->syncWithoutDetaching([
                    $project->id => [
                        'role' => $project->pivot->role,
                        'notes' => $project->pivot->notes,
                    ],
                ]);
            }

            $source->projects()->detach();

            Project::query()
                ->where('user_id', $user->id)
                ->where('owner_person_id', $source->id)
                ->update(['owner_person_id' => $target->id]);

            KnowledgeEntity::query()
                ->where('user_id', $user->id)
                ->where('canonical_type', 'person')
                ->where('canonical_id', $source->id)
                ->update(['canonical_id' => $target->id]);

            $source->forceFill(['status' => PersonStatus::Archived])->save();

            Log::info('directory_person_merged', [
                'user_id' => $user->id,
                'target_id' => $target->id,
                'source_id' => $source->id,
            ]);

            return $target->fresh(['roles', 'identities', 'employeeProfile', 'projects']) ?? $target;
        });
    }
}
