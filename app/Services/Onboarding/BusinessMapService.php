<?php

namespace App\Services\Onboarding;

use App\Enums\BusinessMapStep;
use App\Enums\OnboardingStatus;
use App\Models\BusinessMapProgress;
use App\Models\IntegrationAccount;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectSourceBinding;
use App\Models\User;
use App\Models\UserAssistantProfile;
use App\Models\UserProductivitySetting;
use Illuminate\Support\Facades\Schema;

final class BusinessMapService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(User $user): array
    {
        $progress = $this->progress($user);
        $inferred = $this->infer($user);
        $stored = is_array($progress->steps_json) ? $progress->steps_json : [];
        $steps = [];

        foreach (BusinessMapStep::ordered() as $step) {
            $key = $step->value;
            $done = (bool) ($stored[$key]['done'] ?? false) || ($inferred[$key] ?? false);
            $steps[] = [
                'key' => $key,
                'done' => $done,
                'inferred' => (bool) ($inferred[$key] ?? false),
            ];
        }

        $completed = count(array_filter($steps, fn (array $step): bool => $step['done']));

        return [
            'current_step' => $progress->current_step,
            'completed_count' => $completed,
            'total' => count($steps),
            'completed_at' => optional($progress->completed_at)?->toIso8601String(),
            'banner_dismissed' => (bool) $progress->banner_dismissed,
            'legacy_onboarding' => $user->assistantProfile?->onboarding_status?->value ?? OnboardingStatus::Completed->value,
            'show_banner' => $progress->completed_at === null && ! $progress->banner_dismissed && $completed < count($steps),
            'steps' => $steps,
        ];
    }

    public function progress(User $user): BusinessMapProgress
    {
        $existing = BusinessMapProgress::query()->where('user_id', $user->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $progress = new BusinessMapProgress;
        $progress->forceFill([
            'user_id' => $user->id,
            'current_step' => BusinessMapStep::OwnerProfile->value,
            'steps_json' => [],
            'banner_dismissed' => false,
        ]);
        $progress->save();

        return $progress;
    }

    public function markStep(User $user, BusinessMapStep $step, bool $done = true): BusinessMapProgress
    {
        $progress = $this->progress($user);
        $steps = is_array($progress->steps_json) ? $progress->steps_json : [];
        $steps[$step->value] = [
            'done' => $done,
            'completed_at' => $done ? now()->toIso8601String() : null,
        ];
        $progress->forceFill([
            'steps_json' => $steps,
            'current_step' => $step->value,
        ])->save();

        return $progress->fresh() ?? $progress;
    }

    public function dismissBanner(User $user): void
    {
        $this->progress($user)->forceFill(['banner_dismissed' => true])->save();
    }

    public function complete(User $user): BusinessMapProgress
    {
        $progress = $this->progress($user);
        foreach (BusinessMapStep::ordered() as $step) {
            $this->markStep($user, $step, true);
        }

        $progress = $this->progress($user);
        $progress->forceFill([
            'current_step' => BusinessMapStep::Review->value,
            'completed_at' => now(),
            'banner_dismissed' => true,
        ])->save();

        return $progress->fresh() ?? $progress;
    }

    /**
     * @return array<string, bool>
     */
    public function infer(User $user): array
    {
        $profile = $user->assistantProfile instanceof UserAssistantProfile
            ? $user->assistantProfile
            : UserAssistantProfile::query()->where('user_id', $user->id)->first();

        $hasProject = Project::query()->where('user_id', $user->id)->exists();
        $hasOrg = Schema::hasTable('organizations') && Organization::query()->where('user_id', $user->id)->exists();
        $hasPerson = Schema::hasTable('people') && Person::query()->where('user_id', $user->id)->exists();
        $hasAttach = Schema::hasTable('project_people') && Project::query()
            ->where('user_id', $user->id)
            ->whereHas('people')
            ->exists();
        $hasSource = Schema::hasTable('integration_accounts') && IntegrationAccount::query()->where('user_id', $user->id)->exists();
        $hasBinding = Schema::hasTable('project_source_bindings') && ProjectSourceBinding::query()
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->exists();
        $settings = UserProductivitySetting::query()->where('user_id', $user->id)->first();

        return [
            BusinessMapStep::OwnerProfile->value => filled($user->name) && filled($user->timezone) && filled($profile?->assistant_name),
            BusinessMapStep::BusinessContexts->value => $hasProject || $hasOrg,
            BusinessMapStep::KeyPeople->value => $hasPerson,
            BusinessMapStep::Responsibilities->value => $hasAttach,
            BusinessMapStep::Sources->value => $hasSource,
            BusinessMapStep::Mappings->value => $hasBinding,
            BusinessMapStep::ExecutiveBrief->value => $settings !== null,
            BusinessMapStep::Proactivity->value => $settings !== null,
            BusinessMapStep::Review->value => false,
        ];
    }
}
