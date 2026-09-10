<?php

namespace App\Http\Controllers;

use App\Enums\ExecutiveBriefStatus;
use App\Enums\ExecutiveBriefType;
use App\Models\ExecutiveBrief;
use App\Services\ExecutiveBrief\ExecutiveBriefService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExecutiveBriefController extends Controller
{
    public function __construct(
        private readonly ExecutiveBriefService $briefs,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', ExecutiveBrief::class);

        $query = ExecutiveBrief::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        $type = trim((string) $request->query('type', ''));
        if ($type !== '' && ExecutiveBriefType::tryFrom($type) !== null) {
            $query->where('brief_type', $type);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && ExecutiveBriefStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $date = trim((string) $request->query('date', ''));
        if ($date !== '') {
            $query->whereDate('generated_for', $date);
        }

        $rows = $query->limit(200)->get();

        return Inertia::render('ExecutiveBriefs/Index', [
            'briefs' => $rows->map(fn (ExecutiveBrief $brief): array => [
                'id' => $brief->id,
                'brief_type' => $brief->brief_type instanceof ExecutiveBriefType ? $brief->brief_type->value : (string) $brief->brief_type,
                'origin' => (string) $brief->origin,
                'status' => $brief->status instanceof ExecutiveBriefStatus ? $brief->status->value : (string) $brief->status,
                'run_key' => (string) $brief->run_key,
                'automation_run_id' => $brief->automation_run_id,
                'generated_for' => optional($brief->generated_for)?->toDateString(),
                'generated_at' => optional($brief->generated_at)?->toIso8601String(),
                'delivered_at' => optional($brief->delivered_at)?->toIso8601String(),
                'delivery_status' => $brief->delivery_status,
                'priority_score' => $brief->priority_score,
                'safe_error' => $brief->safe_error,
                'snapshot' => [
                    'sources_attempted' => (int) (data_get($brief->source_snapshot_json, 'sources_attempted') ?? 0),
                    'sources_succeeded' => (int) (data_get($brief->source_snapshot_json, 'sources_succeeded') ?? 0),
                    'sources_failed' => (int) (data_get($brief->source_snapshot_json, 'sources_failed') ?? 0),
                    'items_collected' => (int) (data_get($brief->source_snapshot_json, 'items_collected') ?? 0),
                    'ai_used' => (bool) data_get($brief->source_snapshot_json, 'ai_used'),
                ],
            ])->values()->all(),
            'filters' => [
                'type' => $type !== '' ? $type : null,
                'status' => $status !== '' ? $status : null,
                'date' => $date !== '' ? $date : null,
            ],
            'types' => ExecutiveBriefType::values(),
            'statuses' => ExecutiveBriefStatus::values(),
        ]);
    }

    public function regenerate(Request $request, ExecutiveBrief $executiveBrief): RedirectResponse
    {
        $this->authorize('update', $executiveBrief);

        if ((int) $executiveBrief->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->briefs->generateNow($request->user(), (int) $executiveBrief->id);

        return back();
    }
}
