<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Directory\DirectoryService;
use App\Services\LeadershipReview\LeadershipReviewService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Projects\ProjectService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspaceProjectsController extends Controller
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly DirectoryService $directory,
        private readonly CommitmentService $commitments,
        private readonly LeadershipReviewService $leadership,
        private readonly OwnerLocaleResolver $locales,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $items = $this->projects->listForOwner($request->user())
            ->map(fn (Project $project): array => $this->directory->serializeProjectCard($project))
            ->all();

        return Inertia::render('Jarvis/Projects', [
            'projects' => $items,
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        if ((int) $project->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $project);

        $project->load(['people.roles', 'people.employeeProfile', 'people.projects', 'organizations', 'telegramGroups', 'sourceBindings', 'ownerPerson']);

        return Inertia::render('Jarvis/ProjectShow', [
            'project' => [
                ...$this->directory->serializeProjectCard($project),
                'people' => $project->people->map(fn ($person): array => $this->directory->serializePersonSummary($person))->values()->all(),
                'organizations' => $project->organizations->map(fn ($organization): array => $this->directory->serializeOrganization($organization))->values()->all(),
                'groups' => $project->telegramGroups->map(static fn ($group): array => [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->chat_type,
                    'chat_type' => $group->chat_type,
                    'status' => $group->status->value,
                ])->values()->all(),
                'source_bindings' => $project->sourceBindings->map(static fn ($binding): array => [
                    'id' => $binding->id,
                    'source_type' => $binding->source_type->value,
                    'purpose' => $binding->purpose,
                ])->values()->all(),
                'owner_person' => $project->ownerPerson ? [
                    'id' => $project->ownerPerson->id,
                    'display_name' => $project->ownerPerson->display_name,
                ] : null,
            ],
            'commitments' => $this->commitments->forProject($request->user(), $project)
                ->map(fn ($commitment): array => $this->commitments->serializeSummary($commitment))
                ->values()
                ->all(),
            'process' => $this->leadership->operationalForProject(
                $request->user(),
                $project,
                $this->locales->interfaceLocale($request->user()),
            ),
            'admin_href' => route('projects.show', $project),
        ]);
    }
}
