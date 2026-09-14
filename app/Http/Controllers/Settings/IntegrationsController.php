<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Models\ToolExecutionLog;
use App\Services\Integrations\Google\GoogleOAuthSettingsService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Sources\SourceDashboardService;
use App\Services\Users\UserCapability;
use App\Services\Zoom\ZoomCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationsController extends Controller
{
    public function __construct(
        private readonly IntegrationRegistry $registry,
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleOAuthSettingsService $googleOAuthSettings,
        private readonly SourceDashboardService $sources,
    ) {}

    public function index(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        return redirect()->route('settings.index', ['tab' => 'integrations']);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Request $request): array
    {
        $this->assertAdmin($request);
        $owner = $request->user();

        return [
            'providers' => $this->registry->summariesForOwner($owner),
            'google_accounts' => $this->sources->googleAccounts($owner),
            'telegram_groups' => $this->sources->telegramGroups($owner),
            'external_sources' => $this->sources->workspace($owner)['external'],
            'google_oauth' => $this->googleOAuthSettings->adminPayload(),
            'zoom' => app(ZoomCredentialService::class)->adminPayload($owner),
            'recent_executions' => ToolExecutionLog::query()
                ->where('user_id', $owner->id)
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit((int) config('integrations.recent_executions_limit', 50))
                ->get([
                    'id',
                    'tool_name',
                    'provider',
                    'status',
                    'duration_ms',
                    'error_code',
                    'started_at',
                ])
                ->map(static fn (ToolExecutionLog $log): array => [
                    'id' => $log->id,
                    'time' => optional($log->started_at)?->toIso8601String(),
                    'tool' => $log->tool_name,
                    'provider' => $log->provider,
                    'status' => $log->status?->value ?? (string) $log->status,
                    'duration_ms' => $log->duration_ms,
                    'error_code' => $log->error_code,
                ])
                ->all(),
        ];
    }

    public function showAccount(Request $request, IntegrationAccount $integrationAccount): JsonResponse
    {
        $this->assertAdmin($request);
        $user = $request->user();

        if ((int) $integrationAccount->user_id !== (int) $user->id) {
            abort(403);
        }

        return response()->json($this->accounts->safeSummary($integrationAccount));
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
