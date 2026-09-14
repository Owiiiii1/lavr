<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\Commitment;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\OperationalControl\ProactiveProposalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisWorkspaceCommitmentsController extends Controller
{
    public function __construct(
        private readonly CommitmentService $commitments,
        private readonly ProactiveProposalService $proposals,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Commitment::class);

        return Inertia::render('Jarvis/Commitments', [
            'sections' => $this->commitments->workspaceSections($request->user()),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Commitment::class);

        try {
            $commitment = $this->commitments->createManual($request->user(), $request->validate([
                'title' => ['required', 'string', 'max:190'],
                'expected_result' => ['nullable', 'string', 'max:2000'],
                'person_id' => ['nullable', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'deadline_at' => ['nullable', 'date'],
                'deadline_raw' => ['nullable', 'string', 'max:190'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]));
        } catch (CommitmentException $exception) {
            return back()->withErrors(['title' => $exception->error]);
        }

        return redirect()->route('jarvis.commitments.show', $commitment);
    }

    public function show(Request $request, Commitment $commitment): Response
    {
        if ((int) $commitment->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $commitment);

        return Inertia::render('Jarvis/CommitmentShow', [
            'commitment' => $this->commitments->serialize($commitment),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'proposals' => array_map(
                fn ($proposal): array => $this->proposals->serialize($proposal),
                $this->proposals->pendingForCommitment($request->user(), (int) $commitment->id),
            ),
        ]);
    }

    public function update(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->update(
            $request->user(),
            $commitment,
            $request->validate([
                'title' => ['sometimes', 'string', 'max:190'],
                'expected_result' => ['nullable', 'string', 'max:2000'],
                'person_id' => ['nullable', 'integer'],
                'project_id' => ['nullable', 'integer'],
                'deadline_at' => ['nullable', 'date'],
                'deadline_raw' => ['nullable', 'string', 'max:190'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ]),
        ));
    }

    public function confirm(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->confirmDetected(
            $request->user(),
            $commitment,
            $request->validate([
                'title' => ['nullable', 'string', 'max:190'],
                'expected_result' => ['nullable', 'string', 'max:2000'],
                'person_id' => ['nullable', 'integer'],
                'deadline_at' => ['nullable', 'date'],
                'deadline_raw' => ['nullable', 'string', 'max:190'],
            ]),
        ));
    }

    public function dismiss(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->dismiss($request->user(), $commitment));
    }

    public function cancel(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->cancel($request->user(), $commitment, $request->input('reason')));
    }

    public function likelyDone(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->markLikelyDone($request->user(), $commitment, $request->input('note')));
    }

    public function complete(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, fn (): Commitment => $this->commitments->markConfirmed($request->user(), $commitment, $request->input('note')));
    }

    public function evidence(Request $request, Commitment $commitment): RedirectResponse
    {
        return $this->mutate($request, $commitment, function () use ($request, $commitment): Commitment {
            $validated = $request->validate([
                'evidence_type' => ['required', 'string', 'max:32'],
                'excerpt' => ['nullable', 'string', 'max:280'],
            ]);

            return $this->commitments->addEvidence(
                $request->user(),
                $commitment,
                $validated['evidence_type'],
                $validated['excerpt'] ?? null,
            );
        });
    }

    private function mutate(Request $request, Commitment $commitment, callable $action): RedirectResponse
    {
        if ((int) $commitment->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $commitment);

        try {
            $action();
        } catch (CommitmentException $exception) {
            return back()->withErrors(['status' => $exception->error]);
        }

        return back();
    }
}
