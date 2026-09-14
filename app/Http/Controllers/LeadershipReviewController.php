<?php

namespace App\Http\Controllers;

use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use App\Models\LeadershipReview;
use App\Services\LeadershipReview\LeadershipReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeadershipReviewController extends Controller
{
    public function __construct(
        private readonly LeadershipReviewService $reviews,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', LeadershipReview::class);

        $query = LeadershipReview::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        $type = trim((string) $request->query('type', ''));
        if ($type !== '' && LeadershipReviewType::tryFrom($type) !== null) {
            $query->where('review_type', $type);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && LeadershipReviewStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $rows = $query->limit(200)->get();

        return Inertia::render('LeadershipReviews/Index', [
            'reviews' => $rows->map(fn (LeadershipReview $review): array => [
                'id' => $review->id,
                'review_type' => $review->review_type instanceof LeadershipReviewType ? $review->review_type->value : (string) $review->review_type,
                'origin' => (string) $review->origin,
                'status' => $review->status instanceof LeadershipReviewStatus ? $review->status->value : (string) $review->status,
                'run_key' => (string) $review->run_key,
                'automation_run_id' => $review->automation_run_id,
                'period_start' => optional($review->period_start)?->toDateString(),
                'period_end' => optional($review->period_end)?->toDateString(),
                'generated_at' => optional($review->generated_at)?->toIso8601String(),
                'generated_by' => (string) $review->generated_by,
                'delivery_status' => $review->delivery_status,
                'safe_error' => $review->safe_error,
                'snapshot' => [
                    'sources_attempted' => (int) (data_get($review->source_snapshot_json, 'sources_attempted') ?? 0),
                    'sources_succeeded' => (int) (data_get($review->source_snapshot_json, 'sources_succeeded') ?? 0),
                    'sources_failed' => (int) (data_get($review->source_snapshot_json, 'sources_failed') ?? 0),
                    'commitments' => (int) (data_get($review->metrics_json, 'commitments_total') ?? 0),
                    'meetings' => (int) (data_get($review->metrics_json, 'meetings_total') ?? 0),
                    'findings' => count(data_get($review->findings_json, 'findings') ?? []),
                    'ai_used' => (bool) data_get($review->source_snapshot_json, 'ai_used'),
                ],
            ])->values()->all(),
            'filters' => [
                'type' => $type !== '' ? $type : null,
                'status' => $status !== '' ? $status : null,
            ],
            'types' => LeadershipReviewType::values(),
            'statuses' => LeadershipReviewStatus::values(),
        ]);
    }

    public function regenerate(Request $request, LeadershipReview $leadershipReview): RedirectResponse
    {
        $this->authorize('update', $leadershipReview);

        if ((int) $leadershipReview->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->reviews->generateNow($request->user(), [
            'review_type' => $leadershipReview->review_type instanceof LeadershipReviewType
                ? $leadershipReview->review_type->value
                : (string) $leadershipReview->review_type,
            'period' => 'custom',
            'from' => optional($leadershipReview->period_start)?->timezone((string) ($leadershipReview->timezone ?: 'UTC'))->toDateString(),
            'to' => optional($leadershipReview->period_end)?->timezone((string) ($leadershipReview->timezone ?: 'UTC'))->toDateString(),
            'project_id' => $leadershipReview->project_id,
            'person_id' => $leadershipReview->person_id,
            'meeting_id' => $leadershipReview->meeting_id,
        ]);

        return back();
    }
}
