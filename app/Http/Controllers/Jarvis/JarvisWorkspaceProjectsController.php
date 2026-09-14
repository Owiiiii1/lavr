<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Models\Project;
use App\Models\TelegramGroup;
use App\Services\Commitments\CommitmentService;
use App\Services\Directory\DirectoryService;
use App\Services\LeadershipReview\LeadershipReviewService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\OperationalControl\ProactiveProposalService;
use App\Services\Projects\ProjectService;
use App\Services\Sources\ProjectSourceBindingService;
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
        private readonly ProjectSourceBindingService $sourceBindings,
        private readonly ProactiveProposalService $proposals,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Project::class);

        $collection = $this->projects->listForOwner($request->user());
        $page = max(1, $request->integer('page', 1));
        $perPage = 50;
        $total = $collection->count();

        return Inertia::render('Jarvis/Projects', [
            'projects' => $collection->forPage($page, $perPage)->map(fn (Project $project): array => $this->directory->serializeProjectCard($project))->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
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
                'source_bindings' => $this->sourceBindings->serializeForProject($request->user(), $project),
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
            'proposals' => array_map(
                fn ($proposal): array => $this->proposals->serialize($proposal),
                $this->proposals->pendingForProject($request->user(), (int) $project->id),
            ),
            'admin_href' => route('projects.show', $project),
            'available_google_accounts' => IntegrationAccount::query()
                ->where('user_id', $request->user()->id)
                ->where('provider', 'google')
                ->orderByDesc('id')
                ->get()
                ->map(static fn ($account): array => [
                    'id' => $account->id,
                    'label' => $account->label(),
                    'email' => $account->external_account_email,
                ])
                ->all(),
            'available_telegram_groups' => TelegramGroup::query()
                ->whereHas('conversation', fn ($query) => $query->where('user_id', $request->user()->id))
                ->orderBy('title')
                ->limit(50)
                ->get(['id', 'title', 'chat_type'])
                ->map(static fn (TelegramGroup $group): array => [
                    'id' => $group->id,
                    'title' => $group->title ?: $group->chat_type,
                ])
                ->values()
                ->all(),
        ]);
    }
}
