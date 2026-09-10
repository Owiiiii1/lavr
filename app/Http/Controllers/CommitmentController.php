<?php

namespace App\Http\Controllers;

use App\Models\Commitment;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommitmentController extends Controller
{
    public function __construct(
        private readonly CommitmentService $commitments,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Commitment::class);

        $items = $this->commitments->list(
            $request->user(),
            $request->query('q'),
            $request->query('status'),
            $request->integer('person_id') ?: null,
            $request->integer('project_id') ?: null,
            $request->query('source'),
            $request->boolean('overdue'),
        );

        return Inertia::render('Commitments/Index', [
            'commitments' => $items->map(fn (Commitment $commitment): array => $this->commitments->serializeSummary($commitment))->values()->all(),
            'filters' => [
                'q' => $request->query('q'),
                'status' => $request->query('status'),
                'person_id' => $request->integer('person_id') ?: null,
                'project_id' => $request->integer('project_id') ?: null,
                'source' => $request->query('source'),
                'overdue' => $request->boolean('overdue'),
            ],
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Commitment::class);

        try {
            $commitment = $this->commitments->createManual($request->user(), $request->validate($this->rules()));
        } catch (CommitmentException $exception) {
            return back()->withErrors(['title' => $this->messageFor($exception)]);
        }

        return redirect()->route('commitments.show', $commitment);
    }

    public function show(Request $request, Commitment $commitment): Response
    {
        $this->authorizeOwned($request, $commitment);

        return Inertia::render('Commitments/Show', [
            'commitment' => $this->commitments->serialize($commitment),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'mergeCandidates' => Commitment::query()
                ->where('user_id', $request->user()->id)
                ->whereKeyNot($commitment->id)
                ->whereNull('merged_into_id')
                ->orderByDesc('id')
                ->limit(40)
                ->get(['id', 'title']),
        ]);
    }

    public function update(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');

        try {
            $this->commitments->update($request->user(), $commitment, $request->validate($this->rules(false)));
        } catch (CommitmentException $exception) {
            return back()->withErrors(['title' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function confirm(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');

        try {
            $this->commitments->confirmDetected($request->user(), $commitment, $request->validate($this->rules(false)));
        } catch (CommitmentException $exception) {
            return back()->withErrors(['status' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function dismiss(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');
        $this->commitments->dismiss($request->user(), $commitment);

        return back();
    }

    public function cancel(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');
        $this->commitments->cancel($request->user(), $commitment, $request->input('reason'));

        return back();
    }

    public function likelyDone(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');

        try {
            $this->commitments->markLikelyDone($request->user(), $commitment, $request->input('note'));
        } catch (CommitmentException $exception) {
            return back()->withErrors(['status' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function complete(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');
        $this->commitments->markConfirmed($request->user(), $commitment, $request->input('note'));

        return back();
    }

    public function evidence(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');
        $validated = $request->validate([
            'evidence_type' => ['required', 'string', 'max:32'],
            'excerpt' => ['nullable', 'string', 'max:280'],
        ]);

        try {
            $this->commitments->addEvidence(
                $request->user(),
                $commitment,
                $validated['evidence_type'],
                $validated['excerpt'] ?? null,
            );
        } catch (CommitmentException $exception) {
            return back()->withErrors(['excerpt' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function merge(Request $request, Commitment $commitment): RedirectResponse
    {
        $this->authorizeOwned($request, $commitment, 'update');
        $validated = $request->validate(['duplicate_id' => ['required', 'integer']]);
        $duplicate = Commitment::query()->findOrFail((int) $validated['duplicate_id']);

        try {
            $this->commitments->merge($request->user(), $commitment, $duplicate);
        } catch (CommitmentException $exception) {
            return back()->withErrors(['duplicate_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating = true): array
    {
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:190'],
            'action' => ['nullable', 'string', 'max:190'],
            'expected_result' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:5000'],
            'person_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'deadline_raw' => ['nullable', 'string', 'max:190'],
            'deadline_at' => ['nullable', 'date'],
            'deadline_precision' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function authorizeOwned(Request $request, Commitment $commitment, string $ability = 'view'): void
    {
        if ((int) $commitment->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $commitment);
    }

    private function messageFor(CommitmentException $exception): string
    {
        return match ($exception->error) {
            'invalid_action' => 'Action is required.',
            'invalid_person' => 'Person not found.',
            'invalid_status' => 'That status change is not allowed.',
            'missing_completion_evidence' => 'Add completion evidence first.',
            'invalid_merge' => 'Cannot merge those commitments.',
            'not_found' => 'Not found.',
            default => 'Unable to save the commitment.',
        };
    }
}
