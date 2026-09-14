<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\PersonRoleCode;
use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Directory\DirectoryService;
use App\Services\LeadershipReview\LeadershipReviewService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\OperationalControl\ProactiveProposalService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspacePeopleController extends Controller
{
    public function __construct(
        private readonly DirectoryService $directory,
        private readonly CommitmentService $commitments,
        private readonly LeadershipReviewService $leadership,
        private readonly OwnerLocaleResolver $locales,
        private readonly ProactiveProposalService $proposals,
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

        $page = max(1, $request->integer('page', 1));
        $perPage = 50;
        $total = $people->count();

        return Inertia::render('Jarvis/People', [
            'people' => $people->forPage($page, $perPage)->map(fn (Person $person): array => $this->directory->serializePersonSummary($person))->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
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
            'commitments' => $this->commitments->forPerson($request->user(), $person)
                ->map(fn ($commitment): array => $this->commitments->serializeSummary($commitment))
                ->values()
                ->all(),
            'operational' => $this->leadership->operationalForPerson(
                $request->user(),
                $person,
                $this->locales->interfaceLocale($request->user()),
            ),
            'proposals' => array_map(
                fn ($proposal): array => $this->proposals->serialize($proposal),
                $this->proposals->pendingForPerson($request->user(), (int) $person->id),
            ),
        ]);
    }
}
