<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\BusinessMapStep;
use App\Enums\OwnerLocale;
use App\Enums\ProjectSourceType;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Project;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Directory\DirectoryService;
use App\Services\Onboarding\BusinessMapService;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Projects\ProjectService;
use App\Services\Sources\ProjectSourceBindingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisBusinessSetupController extends Controller
{
    public function __construct(
        private readonly BusinessMapService $map,
        private readonly AssistantProfileService $profiles,
        private readonly ProjectService $projects,
        private readonly DirectoryService $directory,
        private readonly ProductivitySettingsService $productivity,
        private readonly ProjectSourceBindingService $bindings,
    ) {}

    public function show(Request $request): Response
    {
        abort_unless($request->user()?->isOwner(), 403);
        $user = $request->user();

        return Inertia::render('Jarvis/Setup', [
            'map' => $this->map->snapshot($user),
            'profile' => [
                'name' => $user->name,
                'timezone' => $user->timezone,
                'interface_locale' => $user->assistantProfile?->interface_locale ?? OwnerLocale::Uk->value,
                'assistant_locale' => $user->assistantProfile?->assistant_locale ?? OwnerLocale::Uk->value,
                'assistant_name' => $user->assistantProfile?->assistant_name ?? 'LAVR',
                'interaction_style' => $user->assistantProfile?->interaction_style,
            ],
            'projects' => Project::query()->where('user_id', $user->id)->orderBy('name')->limit(50)->get(['id', 'name']),
            'people' => $this->directory->listPeople($user)->take(50)->map(fn ($person): array => [
                'id' => $person->id,
                'display_name' => $person->display_name,
            ])->values()->all(),
            'locales' => OwnerLocale::codes(),
        ]);
    }

    public function saveStep(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isOwner(), 403);
        $user = $request->user();
        $step = BusinessMapStep::tryFrom((string) $request->input('step'));
        if ($step === null) {
            return back()->withErrors(['step' => 'Unknown step.']);
        }

        match ($step) {
            BusinessMapStep::OwnerProfile => $this->saveProfile($request),
            BusinessMapStep::BusinessContexts => $this->saveContexts($request),
            BusinessMapStep::KeyPeople => $this->savePeople($request),
            BusinessMapStep::Responsibilities => $this->saveResponsibilities($request),
            BusinessMapStep::Sources => $this->map->markStep($user, $step, true),
            BusinessMapStep::Mappings => $this->saveMapping($request),
            BusinessMapStep::ExecutiveBrief, BusinessMapStep::Proactivity => $this->savePreferences($request, $step),
            BusinessMapStep::Review => $this->map->complete($user),
        };

        if ($step !== BusinessMapStep::Review) {
            $this->map->markStep($user, $step, true);
        }

        return back();
    }

    public function dismiss(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isOwner(), 403);
        $this->map->dismissBanner($request->user());

        return back();
    }

    private function saveProfile(Request $request): void
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'interface_locale' => ['required', 'string', 'max:16'],
            'assistant_locale' => ['required', 'string', 'max:16'],
            'assistant_name' => ['required', 'string', 'max:80'],
            'interaction_style' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $user->forceFill([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
        ])->save();

        $this->profiles->updateLocales($user, $validated['interface_locale'], $validated['assistant_locale']);
        $this->profiles->updateFields($user, [
            'assistant_name' => $validated['assistant_name'],
            'interaction_style' => $validated['interaction_style'] ?? null,
        ]);
    }

    private function saveContexts(Request $request): void
    {
        $validated = $request->validate([
            'project_name' => ['nullable', 'string', 'max:120'],
            'organization_name' => ['nullable', 'string', 'max:160'],
        ]);

        $user = $request->user();
        if (filled($validated['project_name'] ?? null)) {
            $this->projects->create($user, (string) $validated['project_name']);
        }
        if (filled($validated['organization_name'] ?? null)) {
            $this->directory->createOrganization($user, ['name' => $validated['organization_name']]);
        }
    }

    private function savePeople(Request $request): void
    {
        $validated = $request->validate([
            'display_name' => ['required', 'string', 'max:160'],
            'primary_email' => ['nullable', 'email', 'max:190'],
        ]);

        $this->directory->createPerson($request->user(), $validated);
    }

    private function saveResponsibilities(Request $request): void
    {
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'person_id' => ['required', 'integer'],
        ]);
        $user = $request->user();
        $project = Project::query()->where('user_id', $user->id)->whereKey($validated['project_id'])->firstOrFail();
        $person = Person::query()->where('user_id', $user->id)->whereKey($validated['person_id'])->firstOrFail();
        $this->directory->attachPersonToProject($user, $project, $person, 'owner');
    }

    private function saveMapping(Request $request): void
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer'],
            'source_type' => ['nullable', 'string', 'max:32'],
            'source_id' => ['nullable', 'integer'],
        ]);

        if (! filled($validated['project_id'] ?? null) || ! filled($validated['source_id'] ?? null)) {
            return;
        }

        $user = $request->user();
        $project = Project::query()->where('user_id', $user->id)->whereKey($validated['project_id'])->firstOrFail();
        $type = ProjectSourceType::tryFrom((string) $validated['source_type']);
        if ($type === null) {
            return;
        }

        $this->bindings->bind($user, $project, $type, (int) $validated['source_id']);
    }

    private function savePreferences(Request $request, BusinessMapStep $step): void
    {
        $validated = $request->validate([
            'morning_brief_enabled' => ['sometimes', 'boolean'],
            'morning_brief_local_time' => ['nullable', 'string', 'max:8'],
            'operational_alerts_enabled' => ['sometimes', 'boolean'],
            'third_party_execute' => ['sometimes', 'boolean'],
        ]);

        $this->productivity->update($request->user(), $validated);
        $this->map->markStep($request->user(), $step, true);
    }
}
