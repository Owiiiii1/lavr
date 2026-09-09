<?php

namespace App\Http\Controllers;

use App\Enums\CanonicalEntityType;
use App\Enums\OrganizationStatus;
use App\Models\KnowledgeEntity;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationsController extends Controller
{
    public function __construct(
        private readonly DirectoryService $directory,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Organization::class);

        $organizations = Organization::query()
            ->where('user_id', $request->user()->id)
            ->withCount('projects')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return Inertia::render('Organizations/Index', [
            'organizations' => $organizations->map(fn (Organization $organization): array => $this->directory->serializeOrganization($organization))->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Organization::class);
        $validated = $request->validate($this->rules());

        try {
            $organization = $this->directory->createOrganization($request->user(), $validated);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['name' => $exception->error === 'invalid_name' ? 'A name is required.' : 'Unable to save.']);
        }

        return redirect()->route('organizations.show', $organization);
    }

    public function show(Request $request, Organization $organization): Response
    {
        $this->authorizeOwned($request, $organization);
        $organization->load(['projects.people']);

        $people = Person::query()
            ->where('user_id', $request->user()->id)
            ->whereHas('projects', fn ($query) => $query->whereIn('projects.id', $organization->projects->pluck('id')))
            ->orderBy('display_name')
            ->get(['id', 'display_name']);

        return Inertia::render('Organizations/Show', [
            'organization' => $this->directory->serializeOrganization($organization),
            'people' => $people,
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'knowledgeCandidates' => KnowledgeEntity::query()
                ->where('user_id', $request->user()->id)
                ->where('type', 'organization')
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'update');

        try {
            $this->directory->updateOrganization($request->user(), $organization, $request->validate($this->rules()));
        } catch (DirectoryException $exception) {
            return back()->withErrors(['name' => $exception->error === 'invalid_name' ? 'A name is required.' : 'Unable to save.']);
        }

        return back();
    }

    public function archive(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'archive');
        $this->directory->setOrganizationStatus($request->user(), $organization, OrganizationStatus::Archived);

        return back();
    }

    public function restore(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'update');
        $this->directory->setOrganizationStatus($request->user(), $organization, OrganizationStatus::Active);

        return back();
    }

    public function attachProject(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'update');
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:80'],
        ]);
        $project = Project::query()->findOrFail($validated['project_id']);
        $this->directory->attachOrganizationToProject($request->user(), $project, $organization, $validated['role'] ?? null);

        return back();
    }

    public function detachProject(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'update');
        $this->directory->detachOrganizationFromProject($request->user(), $project, $organization);

        return back();
    }

    public function linkKnowledge(Request $request, Organization $organization): RedirectResponse
    {
        $this->authorizeOwned($request, $organization, 'update');
        $validated = $request->validate(['knowledge_entity_id' => ['required', 'integer']]);
        $entity = KnowledgeEntity::query()->findOrFail($validated['knowledge_entity_id']);
        $this->directory->linkKnowledge($request->user(), $entity, CanonicalEntityType::Organization, $organization->id);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'type' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', 'max:32'],
        ];
    }

    private function authorizeOwned(Request $request, Organization $organization, string $ability = 'view'): void
    {
        if ((int) $organization->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $organization);
    }
}
