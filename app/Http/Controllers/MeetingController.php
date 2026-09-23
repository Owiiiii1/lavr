<?php

namespace App\Http\Controllers;

use App\Enums\MeetingStatus;
use App\Models\Meeting;
use App\Models\MeetingArtifact;
use App\Models\MeetingParticipant;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Project;
use App\Services\Commitments\CommitmentService;
use App\Services\Commitments\Exceptions\CommitmentException;
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

class MeetingController extends Controller
{
    public function __construct(
        private readonly MeetingService $meetings,
        private readonly CommitmentService $commitments,
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

        return Inertia::render('Meetings/Index', [
            'meetings' => $items->map(fn (Meeting $meeting): array => $this->meetings->serializeSummary($meeting))->values()->all(),
            'archived' => $archived,
            'activeCount' => Meeting::query()->where('user_id', $user->id)->where('status', '!=', MeetingStatus::Archived)->count(),
            'archivedCount' => Meeting::query()->where('user_id', $user->id)->where('status', MeetingStatus::Archived)->count(),
            'filters' => [
                'q' => $request->query('q'),
                'project_id' => $request->integer('project_id') ?: null,
                'from' => $request->query('from'),
                'to' => $request->query('to'),
                'analysis_status' => $request->query('analysis_status'),
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
        $validated = $request->validate($this->rules());

        try {
            $meeting = $this->meetings->createManual(
                $request->user(),
                $validated,
                $request->file('transcript'),
                $request->input('pasted_text'),
            );
        } catch (MeetingException $exception) {
            return back()->withErrors(['transcript' => $this->messageFor($exception)]);
        }

        return redirect()->route('meetings.show', $meeting);
    }

    public function storePlanned(Request $request): RedirectResponse
    {
        $this->authorize('create', Meeting::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'started_at' => ['required', 'date'],
            'ended_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:190'],
            'meeting_type' => ['nullable', 'string', 'max:64'],
            'project_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'participants' => ['nullable', 'array', 'max:50'],
            'participants.*.display_name' => ['nullable', 'string', 'max:190'],
            'participants.*.email' => ['nullable', 'email', 'max:190'],
            'calendar_provider' => ['nullable', 'string', 'max:32'],
            'calendar_id' => ['nullable', 'string', 'max:191'],
            'calendar_event_id' => ['nullable', 'string', 'max:191'],
        ]);

        try {
            $meeting = $this->meetings->createPlanned($request->user(), $validated);
        } catch (MeetingException $exception) {
            return back()->withErrors(['title' => $this->messageFor($exception)]);
        }

        return redirect()->route('meetings.show', $meeting);
    }

    public function show(Request $request, Meeting $meeting): Response
    {
        $this->authorizeOwned($request, $meeting);

        return Inertia::render('Meetings/Show', [
            'meeting' => [
                ...$this->meetings->serialize($meeting),
                'commitment_items' => $this->commitments->meetingItems($request->user(), $meeting),
            ],
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'organizations' => Organization::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'pollSeconds' => (int) config('meetings.poll_seconds', 3),
        ]);
    }

    public function update(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');

        try {
            $this->meetings->update($request->user(), $meeting, $request->validate([
                'title' => ['sometimes', 'string', 'max:190'],
                'started_at' => ['nullable', 'date'],
                'ended_at' => ['nullable', 'date'],
                'timezone' => ['nullable', 'string', 'max:64'],
                'location' => ['nullable', 'string', 'max:190'],
                'project_id' => ['nullable', 'integer'],
                'organization_id' => ['nullable', 'integer'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'source_external_id' => ['nullable', 'string', 'max:190'],
            ]));
        } catch (MeetingException $exception) {
            return back()->withErrors(['title' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function archive(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'archive');
        $this->meetings->archive($request->user(), $meeting);

        return redirect()->route('meetings.index');
    }

    public function restore(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');
        $this->meetings->restore($request->user(), $meeting);

        return back();
    }

    public function assignReviewSubject(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');
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
            return back()->withErrors(['review_subject_person_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function rerun(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');

        try {
            $this->meetings->rerunAnalysis($request->user(), $meeting);
        } catch (MeetingException $exception) {
            return back()->withErrors(['analysis' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function retryZoom(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');

        try {
            $this->meetings->retryZoomImport($request->user(), $meeting);
        } catch (MeetingException $exception) {
            return back()->withErrors(['zoom' => $this->messageFor($exception)]);
        } catch (ZoomException $exception) {
            return back()->withErrors(['zoom' => $exception->error]);
        }

        return back();
    }

    public function linkParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');
        $validated = $request->validate(['person_id' => ['required', 'integer']]);

        try {
            $this->meetings->linkParticipant($request->user(), $meeting, $participant, (int) $validated['person_id']);
        } catch (MeetingException $exception) {
            return back()->withErrors(['person_id' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function unlinkParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');
        $this->meetings->unlinkParticipant($request->user(), $meeting, $participant);

        return back();
    }

    public function createPersonFromParticipant(Request $request, Meeting $meeting, MeetingParticipant $participant): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');

        try {
            $this->meetings->createPersonFromParticipant($request->user(), $meeting, $participant, $request->validate([
                'display_name' => ['nullable', 'string', 'max:160'],
                'primary_email' => ['nullable', 'email', 'max:190'],
                'roles' => ['nullable', 'array'],
            ]));
        } catch (MeetingException $exception) {
            return back()->withErrors(['display_name' => $this->messageFor($exception)]);
        }

        return back();
    }

    public function downloadArtifact(Request $request, Meeting $meeting, MeetingArtifact $artifact): StreamedResponse
    {
        $this->authorizeOwned($request, $meeting);

        return $this->meetings->downloadArtifact($request->user(), $meeting, $artifact);
    }

    public function promoteCommitment(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->authorizeOwned($request, $meeting, 'update');
        $validated = $request->validate(['index' => ['required', 'integer', 'min:0']]);

        try {
            $commitment = $this->commitments->promoteMeetingItem($request->user(), $meeting, (int) $validated['index']);
        } catch (CommitmentException $exception) {
            return back()->withErrors(['commitment' => $this->messageForCommitment($exception)]);
        }

        return back()->with('promoted_commitment_id', $commitment->id);
    }

    private function messageForCommitment(CommitmentException $exception): string
    {
        return match ($exception->error) {
            'invalid_item', 'missing_analysis' => 'That suggestion cannot be promoted.',
            'not_found' => 'Not found.',
            default => 'Unable to promote the commitment.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:190'],
            'started_at' => ['nullable', 'date'],
            'ended_at' => ['nullable', 'date'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'location' => ['nullable', 'string', 'max:190'],
            'project_id' => ['nullable', 'integer'],
            'organization_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source_external_id' => ['nullable', 'string', 'max:190'],
            'transcript' => ['nullable', 'file', 'max:'.(MeetingConfig::maxFileSizeMb() * 1024)],
            'pasted_text' => ['nullable', 'string', 'max:'.MeetingConfig::maxPasteChars()],
            'review_subject_person_id' => ['nullable', 'integer'],
            'skip_leadership_review' => ['sometimes', 'boolean'],
        ];
    }

    private function authorizeOwned(Request $request, Meeting $meeting, string $ability = 'view'): void
    {
        if ((int) $meeting->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize($ability, $meeting);
    }

    private function messageFor(MeetingException $exception): string
    {
        return match ($exception->error) {
            'unsupported_format' => 'Supported formats: txt, vtt, srt, md.',
            'file_too_large' => 'That file is too large.',
            'empty_transcript' => 'Add a transcript file or paste text.',
            'ambiguous_source' => 'Use either a file or pasted text.',
            'missing_transcript' => 'This meeting has no transcript.',
            'already_processing' => 'Analysis is already running.',
            'missing_title' => 'Add a meeting title.',
            'missing_start' => 'Add a start date and time.',
            'invalid_range' => 'The meeting must end after it starts.',
            'invalid_date' => 'Use a valid date and time.',
            'invalid_calendar_reference' => 'Provide provider, calendar id and event id together.',
            'invalid_project' => 'That project was not found.',
            'invalid_organization' => 'That organization was not found.',
            'not_found' => 'Not found.',
            default => 'Unable to save the meeting.',
        };
    }
}
