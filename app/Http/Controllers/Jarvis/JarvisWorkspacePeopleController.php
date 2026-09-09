<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\PersonRoleCode;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Project;
use App\Services\Directory\DirectoryService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspacePeopleController extends Controller
{
    public function __construct(
        private readonly DirectoryService $directory,
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

        return Inertia::render('Jarvis/People', [
            'people' => $people->map(fn (Person $person): array => $this->directory->serializePersonSummary($person))->values()->all(),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'role' => (string) $request->query('role', ''),
                'project_id' => $request->integer('project_id') ?: null,
                'status' => (string) $request->query('status', ''),
            ],
            'roleOptions' => PersonRoleCode::values(),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Person $person): Response
    {
        if ((int) $person->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $person);

        $person->load(['roles', 'identities', 'employeeProfile.manager', 'projects', 'knowledgeEntities']);

        return Inertia::render('Jarvis/PersonShow', [
            'person' => $this->directory->serializePerson($person),
        ]);
    }
}
