<?php

namespace App\Http\Controllers\Settings;

use App\Enums\IntegrationAccountStatus;
use App\Http\Controllers\Controller;
use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleConnectionService;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\SourceDeletionService;
use App\Services\Users\UserCapability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly GoogleOAuthService $oauth,
        private readonly IntegrationAccountService $accounts,
        private readonly SourceDeletionService $deletion,
        private readonly GoogleCredentialService $credentials,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        $additionalScopes = [];
        if ($request->query('intent') === 'calendar') {
            $additionalScopes = $this->oauth->calendarScopes();
        } elseif ($request->query('intent') === 'gmail') {
            $additionalScopes = $this->oauth->gmailScopes();
        }

        try {
            return redirect()->away($this->connections->authorizationUrl($request->user(), $additionalScopes));
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);

        try {
            $this->connections->complete(
                $request->user(),
                $request->query('state'),
                $request->query('code'),
                $request->query('error'),
            );

            return redirect()
                ->route('settings.index', ['tab' => 'integrations'])
                ->with('success', 'Google account connected.');
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer'],
        ]);
        $owner = $request->user();

        $query = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->whereIn('status', [
                IntegrationAccountStatus::Connected,
                IntegrationAccountStatus::Error,
                IntegrationAccountStatus::Revoked,
            ]);

        if (! empty($validated['account_id'])) {
            $query->whereKey((int) $validated['account_id']);
        }

        $account = $query
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            return redirect()
                ->route('settings.index', ['tab' => 'integrations'])
                ->with('error', 'No Google account is connected.');
        }

        try {
            $revokedRemotely = $this->deletion->removeGoogle($owner, $account);
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }

        $redirect = redirect()->route('settings.index', ['tab' => 'integrations'])
            ->with('success', 'Google account disconnected.');

        if (! $revokedRemotely) {
            $redirect->with('warning', 'Google could not be notified. Local credentials were removed.');
        }

        return $redirect;
    }

    public function update(Request $request, IntegrationAccount $integrationAccount): RedirectResponse
    {
        $this->assertAdmin($request);
        $owner = $request->user();
        if ((int) $integrationAccount->user_id !== (int) $owner->id || $integrationAccount->provider !== 'google') {
            abort(404);
        }

        $validated = $request->validate([
            'display_label' => ['nullable', 'string', 'max:80'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('display_label', $validated) && $validated['display_label'] !== null) {
            $this->accounts->setLabel($integrationAccount, (string) $validated['display_label']);
        }

        if (array_key_exists('enabled', $validated)) {
            $this->accounts->setEnabled($integrationAccount, (bool) $validated['enabled']);
        }

        return redirect()
            ->route('settings.index', ['tab' => 'integrations'])
            ->with('success', 'Google account updated.');
    }

    public function test(Request $request, IntegrationAccount $integrationAccount): RedirectResponse
    {
        $this->assertAdmin($request);
        $owner = $request->user();
        if ((int) $integrationAccount->user_id !== (int) $owner->id || $integrationAccount->provider !== 'google') {
            abort(404);
        }

        try {
            $this->credentials->getValidAccessToken($integrationAccount);
            $this->accounts->recordAuthSuccess($integrationAccount);
        } catch (IntegrationException $exception) {
            return $this->backToIntegrations($exception);
        }

        return redirect()
            ->route('settings.index', ['tab' => 'integrations'])
            ->with('success', 'Google account connection is healthy.');
    }

    private function backToIntegrations(IntegrationException $exception): RedirectResponse
    {
        return redirect()
            ->route('settings.index', ['tab' => 'integrations'])
            ->with('error', $this->safeMessage($exception));
    }

    private function safeMessage(IntegrationException $exception): string
    {
        return match ($exception->error) {
            'configuration_missing' => 'Google OAuth is not configured.',
            'oauth_access_denied' => 'Google authorization was cancelled.',
            'oauth_invalid_state' => 'Google authorization could not be verified. Try connecting again.',
            'refresh_revoked' => 'Google access was revoked. Reconnect required.',
            'google_unavailable' => 'Google is temporarily unavailable.',
            default => 'Google authorization failed.',
        };
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if ($user === null || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            abort(403);
        }
    }
}
