<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Users\UserCapability;
use App\Services\Zoom\ZoomCredentialService;
use App\Services\Zoom\ZoomOAuthClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ZoomIntegrationController extends Controller
{
    public function __construct(
        private readonly ZoomCredentialService $credentials,
        private readonly ZoomOAuthClient $oauth,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $validated = $request->validate([
            'account_id' => ['nullable', 'string', 'max:190'],
            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $this->credentials->save($request->user(), [
            'account_id' => $validated['account_id'] ?? null,
            'client_id' => $validated['client_id'] ?? null,
            'client_secret' => $validated['client_secret'] ?? null,
            'webhook_secret' => $validated['webhook_secret'] ?? null,
            'enabled' => $request->boolean('enabled'),
        ]);

        return back()->with('success', 'Zoom configuration saved.');
    }

    public function test(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        $account = $this->credentials->accountForOwner($request->user());

        if ($account === null || ! $this->credentials->hasOAuthCredentials($account)) {
            return back()->with('error', 'Save Zoom account ID, client ID, and client secret first.');
        }

        $result = $this->oauth->testConnection($account);

        if ($result['ok']) {
            return back()->with('success', 'Zoom connection succeeded.');
        }

        $message = match ($result['error']) {
            'blocked_auth' => 'Zoom rejected the credentials.',
            'rate_limited' => 'Zoom rate limited the test. Try again shortly.',
            'network' => 'Could not reach Zoom.',
            default => 'Zoom connection failed.',
        };

        return back()->with('error', $message);
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        $account = $this->credentials->accountForOwner($request->user());

        if ($account !== null) {
            $this->oauth->forgetToken($account);
            app(IntegrationAccountService::class)->disconnect($account, false);
        }

        return back()->with('success', 'Zoom disconnected.');
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
