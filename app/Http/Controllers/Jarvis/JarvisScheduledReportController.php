<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Reports\ScheduledReportException;
use App\Services\Reports\ScheduledReportService;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisScheduledReportController extends Controller
{
    public function __construct(
        private readonly ScheduledReportService $reports,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $this->assertReports($user);

        try {
            $panel = $this->reports->panelFor($user);
        } catch (ScheduledReportException $exception) {
            return $this->error($exception);
        }

        if ($this->wantsJsonPanel($request)) {
            return response()->json($panel);
        }

        return Inertia::render('Jarvis/Reports', [
            'reports' => $panel,
        ]);
    }

    public function pause(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->pauseOwned($user, $id));
    }

    public function resume(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->resumeOwned($user, $id));
    }

    public function cancel(Request $request, int $report): JsonResponse
    {
        return $this->mutate($request, $report, fn ($user, $id) => $this->reports->cancelOwned($user, $id));
    }

    private function mutate(Request $request, int $report, callable $action): JsonResponse
    {
        $user = $request->user();
        $this->assertReports($user);

        try {
            $action($user, $report);

            return $this->panel($user);
        } catch (ScheduledReportException $exception) {
            return $this->error($exception);
        }
    }

    private function panel($user): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'active_count' => $this->reports->activeCount($user),
            ...$this->reports->panelFor($user),
        ]);
    }

    private function error(ScheduledReportException $exception): JsonResponse
    {
        $status = match ($exception->error) {
            'not_found' => 404,
            'capability_denied' => 403,
            default => 422,
        };

        return response()->json([
            'error' => $exception->error,
            'message' => $exception->getMessage(),
            'candidates' => $exception->candidates,
        ], $status);
    }

    private function assertReports($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::SCHEDULED_REPORTS)) {
            abort(403);
        }
    }

    private function wantsJsonPanel(Request $request): bool
    {
        return $request->expectsJson() && $request->header('X-Inertia') === null;
    }
}
