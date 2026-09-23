<?php

namespace App\Http\Controllers\Jarvis;

use App\Enums\MeetingStatus;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingArtifact;
use App\Models\MeetingParticipant;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
use App\Services\LeadershipReview\LeadershipReviewService;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Meetings\Exceptions\MeetingException;
use App\Services\Meetings\MeetingConfig;
use App\Services\Meetings\MeetingService;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Zoom\Exceptions\ZoomException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class JarvisWorkspaceMeetingsController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly CommitmentService $commitments,
        private readonly LeadershipReviewService $leadership,
        private readonly OwnerLocaleResolver $locales,
        private readonly ProductivitySettingsService $productivity,
    ) {}

    public function index(Request $request): Response
    {
        return $this->renderIndex($request, archived: false);
    }

    public function archived(Request $request): Response
    {
        return $this->renderIndex($request, archived: true);
    }

    private function renderIndex(Request $request, bool $archived): Response
    {
        $this->authorize('viewAny', Meeting::class);
        $user = $request->user();

        $items = $this->meetings->list(
            $user,
            $request->query('q'),
            $request->integer('project_id') ?: null,
            $request->query('from'),
            $request->query('to'),
            $request->query('analysis_status'),
            $archived ? MeetingStatus::Archived->value : null,
        );

        return Inertia::render('Jarvis/Meetings', [
            'meetings' => $items->map(fn (Meeting $meeting): array => $this->meetings->serializeSummary($meeting))->values()->all(),
            'archived' => $archived,
            'activeCount' => Meeting::query()->where('user_id', $user->id)->where('status', '!=', MeetingStatus::Archived)->count(),
            'archivedCount' => Meeting::query()->where('user_id', $user->id)->where('status', MeetingStatus::Archived)->count(),
            'filters' => [
                'q' => (string) $request->query('q', ''),
                'project_id' => $request->integer('project_id') ?: null,
                'from' => (string) $request->query('from', ''),
                'to' => (string) $request->query('to', ''),
                'analysis_status' => (string) $request->query('analysis_status', ''),
            ],
            'projects' => Project::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::query()->where('user_id', $user->id)->orderBy('name')->get(['id', 'name']),
            'people' => Person::query()->where('user_id', $user->id)->orderBy('display_name')->get(['id', 'display_name']),
            'defaultReviewPersonId' => $this->productivity->for($user)->default_review_person_id,
            'maxFileMb' => MeetingConfig::maxFileSizeMb(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Meeting::class);

        try {
            $meeting = $this->meetings->createManual(
                $request->user(),
                $request->validate([
                    'title' => ['nullable', 'string', 'max:190'],
                    'started_at' => ['nullable', 'date'],
                    'project_id' => ['nullable', 'integer'],
                    'organization_id' => ['nullable', 'integer'],
                    'transcript' => ['nullable', 'file', 'max:'.(MeetingConfig::maxFileSizeMb() * 1024)],
                    'pasted_text' => ['nullable', 'string', 'max:'.MeetingConfig::maxPasteChars()],
                    'review_subject_person_id' => ['nullable', 'integer'],
                    'skip_leadership_review' => ['sometimes', 'boolean'],
                ]),
                $request->file('transcript'),
                $request->input('pasted_text'),
            );
        } catch (MeetingException $exception) {
            return back()->withErrors(['transcript' => $exception->error]);
        }

        return redirect()->route('jarvis.meetings.show', $meeting);
    }

    public function storePlanned(Request $request): RedirectResponse
    {
        $this->authorize('create', Meeting::class);

        try {
            $meeting = $this->meetings->createPlanned($request->user(), $request->validate([
                'title' => ['required', 'string', 'max:190'],
                'started_at' => ['required', 'date'],
                'ended_at' => ['nullable', 'date'],
                'timezone' => ['nullable', 'string', 'max:64'],
                'location' => ['nullable', 'string', 'max:190'],
                'project_id' => ['nullable', 'integer'],
                'organization_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'participants' => ['nullable', 'array', 'max:50'],
                'participants.*.display_name' => ['nullable', 'string', 'max:190'],
                'participants.*.email' => ['nullable', 'email', 'max:190'],
            ]));
        } catch (MeetingException $exception) {
            return back()->withErrors(['title' => $exception->error]);
        }

        return redirect()->route('jarvis.meetings.show', $meeting);
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $meeting);

        return Inertia::render('Jarvis/MeetingShow', [
            'meeting' => [
                ...$this->meetings->serialize($meeting),
                'commitment_items' => $this->commitments->meetingItems($request->user(), $meeting),
            ],
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'pollSeconds' => (int) config('meetings.poll_seconds', 3),
            'meeting_quality' => $this->leadership->meetingQuality(
                $request->user(),
                $meeting,
                $this->locales->interfaceLocale($request->user()),
            ),
        ]);
    }

    public function update(Request $request, Meeting $meeting): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);

        try {
            $this->meetings->update($request->user(), $meeting, $request->validate([
                'title' => ['sometimes', 'string', 'max:190'],
                'started_at' => ['nullable', 'date'],
                'project_id' => ['nullable', 'integer'],
                'organization_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string', 'max:5000'],
            ]));
        } catch (MeetingException $exception) {
            return back()->withErrors(['title' => $exception->error]);
        }

        return back();
    }

    public function assignReviewSubject(Request $request, Meeting $meeting): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);
        $validated = $request->validate([
            'review_subject_person_id' => ['nullable', 'integer'],
            'skip_leadership_review' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->meetings->assignReviewSubject(
                $request->user(),
                $meeting,
                isset($validated['review_subject_person_id']) ? (int) $validated['review_subject_person_id'] : null,
                (bool) ($validated['skip_leadership_review'] ?? false),
            );
        } catch (MeetingException $exception) {
            return back()->withErrors(['review_subject_person_id' => $exception->error]);
        }

        return back();
    }

    public function rerun(Request $request, Meeting $meeting): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);

        try {
            $this->meetings->rerunAnalysis($request->user(), $meeting);
        } catch (MeetingException $exception) {
            return back()->withErrors(['analysis' => $exception->error]);
        }

        return back();
    }

    public function retryZoom(Request $request, Meeting $meeting): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);

        try {
            $this->meetings->retryZoomImport($request->user(), $meeting);
        } catch (MeetingException $exception) {
            return back()->withErrors(['zoom' => $exception->error]);
        } catch (ZoomException $exception) {
            return back()->withErrors(['zoom' => $exception->error]);
        }

        return back();
    }

    public function linkParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);
        $validated = $request->validate(['person_id' => ['required', 'integer']]);

        try {
            $this->meetings->linkParticipant($request->user(), $meeting, $participant, (int) $validated['person_id']);
        } catch (MeetingException $exception) {
            return back()->withErrors(['person_id' => $exception->error]);
        }

        return back();
    }

    public function unlinkParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);
        $this->meetings->unlinkParticipant($request->user(), $meeting, $participant);

        return back();
    }

    public function createPersonFromParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);

        try {
            $this->meetings->createPersonFromParticipant($request->user(), $meeting, $participant);
        } catch (MeetingException $exception) {
            return back()->withErrors(['display_name' => $exception->error]);
        }

        return back();
    }

    public function downloadArtifact(Request $request, Meeting $meeting, MeetingArtifact $artifact): StreamedResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $meeting);

        return $this->meetings->downloadArtifact($request->user(), $meeting, $artifact);
    }

    public function promoteCommitment(Request $request, Meeting $meeting): RedirectResponse
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('update', $meeting);
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        try {
            $this->commitments->promoteMeetingItem($request->user(), $meeting, (int) $validated['index']);
        } catch (CommitmentException $exception) {
            return back()->withErrors(['commitment' => $exception->error]);
        }

        return back();
    }
}
