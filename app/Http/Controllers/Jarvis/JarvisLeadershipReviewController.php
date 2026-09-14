<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Models\LeadershipReview;
use App\Models\Meeting;
use App\Models\Person;
use App\Models\Project;
use App\Services\LeadershipReview\LeadershipReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisLeadershipReviewController extends Controller
{
    public function __construct(
        private readonly LeadershipReviewService $reviews,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeadershipReview::class);

        $paginator = $this->reviews->paginate($request->user(), [
            'type' => (string) $request->query('type', ''),
            'status' => (string) $request->query('status', ''),
            'project_id' => $request->integer('project_id') ?: null,
            'person_id' => $request->integer('person_id') ?: null,
            'meeting_id' => $request->integer('meeting_id') ?: null,
        ]);

        return Inertia::render('Jarvis/Leadership/Index', [
            'reviews' => $paginator->through(fn (LeadershipReview $review): array => $this->reviews->serializeSummary($review)),
            'filters' => [
                'type' => (string) $request->query('type', ''),
                'status' => (string) $request->query('status', ''),
            ],
            'projects' => Project::query()->where('user_id', $request->user()->id)->orderBy('name')->get(['id', 'name']),
            'people' => Person::query()->where('user_id', $request->user()->id)->orderBy('display_name')->get(['id', 'display_name']),
            'meetings' => Meeting::query()->where('user_id', $request->user()->id)->orderByDesc('id')->limit(40)->get(['id', 'title']),
        ]);
    }

    public function show(Request $request, LeadershipReview $review): Response
    {
        if ((int) $review->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->authorize('view', $review);

        return Inertia::render('Jarvis/Leadership/Show', [
            'review' => $this->reviews->serialize($review),
        ]);
    }

    public function generate(Request $request): RedirectResponse
    {
        $this->authorize('create', LeadershipReview::class);

        $validated = $request->validate([
            'review_type' => ['required', 'string', 'in:owner,project,meeting,team,person'],
            'period' => ['required', 'string', 'in:7,30,custom'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'project_id' => ['nullable', 'integer'],
            'person_id' => ['nullable', 'integer'],
            'meeting_id' => ['nullable', 'integer'],
        ]);

        $review = $this->reviews->generateNow($request->user(), $validated);

        return redirect()->route('jarvis.leadership.show', $review);
    }
}
