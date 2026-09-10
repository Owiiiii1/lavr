<?php

namespace App\Http\Controllers;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationRetryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AutomationRunController extends Controller
{
    public function __construct(
        private readonly AutomationRetryService $retries,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AutomationRun::class);

        $query = AutomationRun::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id');

        $type = trim((string) $request->query('type', ''));
        if ($type !== '' && AutomationType::tryFrom($type) !== null) {
            $query->where('automation_type', $type);
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '' && AutomationRunOutcome::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if ($request->boolean('failed')) {
            $query->whereIn('status', [AutomationRunOutcome::Failed, AutomationRunOutcome::Retryable]);
        }

        $automationId = $request->integer('automation_id');
        if ($automationId > 0) {
            $query->where('automation_id', $automationId);
        }

        $date = trim((string) $request->query('date', ''));
        if ($date !== '') {
            $query->whereDate('started_at', $date);
        }

        $runs = $query->limit(200)->get();

        return Inertia::render('AutomationRuns/Index', [
            'runs' => $runs->map(fn (AutomationRun $run): array => [
                'id' => $run->id,
                'run_key' => $run->run_key,
                'automation_type' => $run->automation_type instanceof AutomationType ? $run->automation_type->value : (string) $run->automation_type,
                'automation_id' => $run->automation_id,
                'started_at' => optional($run->started_at)?->toIso8601String(),
                'finished_at' => optional($run->finished_at)?->toIso8601String(),
                'duration_ms' => $run->durationMs(),
                'status' => $run->status instanceof AutomationRunOutcome ? $run->status->value : (string) $run->status,
                'outcome_code' => $run->outcome_code,
                'delivery_status' => $run->delivery_status,
                'attempt' => $run->attempt,
                'safe_error' => $run->safe_error,
                'retryable' => $run->status === AutomationRunOutcome::Failed || $run->status === AutomationRunOutcome::Retryable,
            ])->values()->all(),
            'filters' => [
                'type' => $type !== '' ? $type : null,
                'status' => $status !== '' ? $status : null,
                'date' => $date !== '' ? $date : null,
                'automation_id' => $automationId > 0 ? $automationId : null,
                'failed' => $request->boolean('failed'),
            ],
            'types' => AutomationType::values(),
            'statuses' => AutomationRunOutcome::values(),
        ]);
    }

    public function retry(Request $request, AutomationRun $automationRun): RedirectResponse
    {
        $this->authorize('update', $automationRun);

        if ((int) $automationRun->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->retries->retry($automationRun);

        return back();
    }
}
