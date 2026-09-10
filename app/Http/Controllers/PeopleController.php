<?php

namespace App\Http\Controllers;

use App\Enums\CanonicalEntityType;
use App\Enums\DirectoryPartyType;
use App\Enums\DirectoryRelationType;
use App\Enums\PersonIdentityType;
use App\Enums\PersonRoleCode;
use App\Enums\PersonStatus;
use App\Models\DirectoryRelationship;
use App\Models\KnowledgeEntity;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Directory\PersonMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeopleController extends Controller
{
    public function __construct(
        private readonly DirectoryService $directory,
        private readonly PersonMergeService $merge,
        private readonly CommitmentService $commitments,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Person::class);

        $people = $this->directory->listPeople(
            $request->user(),
            $request->query('q'),
            $request->query('role'),
            $request->integer('project_id') ?: null,
            $request->query('status'),
        );

        return Inertia::render('People/Index', [
            'people' => $people->map(fn (Person $person): array => $this->directory->serializePersonSummary($person))->values()->all(),
            'filters' => [
                'q' => $request->query('q'),
                'role' => $request->query('role'),
                'project_id' => $request->integer('project_id') ?: null,
                'status' => $request->query('status'),
            ],
            'roleOptions' => PersonRoleCode::values(),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Person::class);
        $validated = $request->validate($this->personRules());

        try {
            $person = $this->directory->createPerson($request->user(), $validated);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['display_name' => $this->messageFor($exception)]);
        }

        return redirect()->route('people.show', $person);
    }

    public function show(Request $request, Person $person): Response
    {
        $this->authorizeOwned($request, $person);

        $person->load(['roles', 'identities', 'employeeProfile.manager', 'projects', 'knowledgeEntities']);

        return Inertia::render('People/Show', [
            'person' => $this->directory->serializePerson($person),
            'employee' => $person->employeeProfile,
            'relationships' => $this->relationshipsFor($request, $person),
            'roleOptions' => PersonRoleCode::values(),
            'identityTypes' => PersonIdentityType::values(),
            'relationTypes' => DirectoryRelationType::values(),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'people' => Person::query()->where('user_id', $request->user()->id)->whereKeyNot($person->id)->orderBy('display_name')->get(['id', 'display_name']),
            'knowledgeCandidates' => KnowledgeEntity::query()
                ->where('user_id', $request->user()->id)
                ->where('type', 'person')
                ->orderBy('name')
                ->limit(50)
                ->get(['id', 'name']),
            'commitments' => $this->commitments->forPerson($request->user(), $person)
                ->map(fn ($commitment): array => $this->commitments->serializeSummary($commitment))
                ->values()
                ->all(),
        ]);
    }

    public function update(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');

        try {
            $this->directory->updatePerson($request->user(), $person, $request->validate($this->personRules(false)));
        } catch (DirectoryException $exception) {
            return back()->withErrors(['display_name' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function archive(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'archive');
        $this->directory->setPersonStatus($request->user(), $person, PersonStatus::Archived);

        return back();
    }

    public function restore(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $this->directory->setPersonStatus($request->user(), $person, PersonStatus::Active);

        return back();
    }

    public function updateEmployee(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate([
            'position' => ['nullable', 'string', 'max:160'],
            'department' => ['nullable', 'string', 'max:160'],
            'manager_person_id' => ['nullable', 'integer'],
            'employment_status' => ['nullable', 'string', 'max:32'],
            'responsibilities' => ['nullable'],
            'areas_of_ownership' => ['nullable'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->directory->upsertEmployeeProfile($request->user(), $person, $validated);

        return back();
    }

    public function storeIdentity(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate([
            'type' => ['required', 'string'],
            'value' => ['required', 'string', 'max:190'],
        ]);
        $type = PersonIdentityType::tryFrom($validated['type']);

        if ($type === null) {
            return back()->withErrors(['type' => 'Invalid identity type.']);
        }

        try {
            $this->directory->addIdentity($request->user(), $person, $type, $validated['value']);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['value' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function attachProject(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'role' => ['nullable', 'string', 'max:80'],
        ]);
        $project = Project::query()->findOrFail($validated['project_id']);

        try {
            $this->directory->attachPersonToProject($request->user(), $project, $person, $validated['role'] ?? null);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['project_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function detachProject(Request $request, Person $person, Project $project): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $this->directory->detachPersonFromProject($request->user(), $project, $person);

        return back();
    }

    public function storeRelationship(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate([
            'relation_type' => ['required', 'string'],
            'object_type' => ['required', 'string'],
            'object_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $relation = DirectoryRelationType::tryFrom($validated['relation_type']);
        $objectType = DirectoryPartyType::tryFrom($validated['object_type']);

        if ($relation === null || $objectType === null) {
            return back()->withErrors(['relation_type' => 'Invalid relationship.']);
        }

        try {
            $this->directory->relate(
                $request->user(),
                DirectoryPartyType::Person,
                $person->id,
                $relation,
                $objectType,
                (int) $validated['object_id'],
                $validated['notes'] ?? null,
            );
        } catch (DirectoryException $exception) {
            return back()->withErrors(['relation_type' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function linkKnowledge(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate(['knowledge_entity_id' => ['required', 'integer']]);
        $entity = KnowledgeEntity::query()->findOrFail($validated['knowledge_entity_id']);

        try {
            $this->directory->linkKnowledge($request->user(), $entity, CanonicalEntityType::Person, $person->id);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['knowledge_entity_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function merge(Request $request, Person $person): RedirectResponse
    {
        $this->authorizeOwned($request, $person, 'update');
        $validated = $request->validate(['source_person_id' => ['required', 'integer']]);
        $source = Person::query()->findOrFail($validated['source_person_id']);

        try {
            $this->merge->merge($request->user(), $person, $source);
        } catch (DirectoryException $exception) {
            return back()->withErrors(['source_person_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function personRules(bool $creating = true): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'display_name' => [$creating ? 'nullable' : 'sometimes', 'string', 'max:160'],
            'primary_email' => ['nullable', 'email', 'max:190'],
            'primary_phone' => ['nullable', 'string', 'max:40'],
            'telegram_username' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'preferred_language' => ['nullable', 'string', 'max:8'],
            'status' => ['nullable', 'string', 'max:32'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string'],
        ];
    }

    private function authorizeOwned(Request $request, Person $person, string $ability = 'view'): void
    {
        if ((int) $person->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $person);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relationshipsFor(Request $request, Person $person): array
    {
        return DirectoryRelationship::query()
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($person): void {
                $query->where(function ($inner) use ($person): void {
                    $inner->where('subject_type', 'person')->where('subject_id', $person->id);
                })->orWhere(function ($inner) use ($person): void {
                    $inner->where('object_type', 'person')->where('object_id', $person->id);
                });
            })
            ->orderBy('id')
            ->get()
            ->map(fn ($row): array => [
                'id' => $row->id,
                'subject_type' => $row->subject_type->value,
                'subject_id' => $row->subject_id,
                'relation_type' => $row->relation_type->value,
                'object_type' => $row->object_type->value,
                'object_id' => $row->object_id,
                'notes' => $row->notes,
            ])
            ->all();
    }

    private function messageFor(DirectoryException $exception): string
    {
        return match ($exception->error) {
            'identity_taken' => 'That identity already belongs to another person.',
            'invalid_name' => 'A name is required.',
            'invalid_relationship' => 'That relationship is not allowed.',
            'invalid_merge' => 'Cannot merge a person into themselves.',
            default => 'Unable to save the person.',
        };
    }
}
