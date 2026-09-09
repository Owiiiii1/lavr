<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\WebApp\TelegramWebAppAuthenticator;
use App\Services\Telegram\WebApp\TelegramWebAppAuthException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramWebAppController extends Controller
{
    public function __construct(
        private readonly TelegramWebAppAuthenticator $authenticator,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Telegram/WebAppBoot', [
            'startParam' => $this->startParamFromRequest($request),
        ]);
    }

    public function store(Request $request): RedirectResponse|Response
    {
        $validated = $request->validate([
            'init_data' => ['required', 'string', 'max:8192'],
            'start_param' => ['nullable', 'string', 'max:64'],
            'next' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->authenticator->authenticate(
                $request,
                (string) $validated['init_data'],
                isset($validated['start_param']) ? (string) $validated['start_param'] : null,
                isset($validated['next']) ? (string) $validated['next'] : null,
            );
        } catch (TelegramWebAppAuthException $exception) {
            return Inertia::render('Telegram/WebAppBlocked', [
                'reason' => $exception->reason,
            ]);
        }

        return redirect()->to($result['path']);
    }

    private function startParamFromRequest(Request $request): ?string
    {
        $candidates = [
            $request->query('startapp'),
            $request->query('start_param'),
            $request->query('tgWebAppStartParam'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '' && strlen($value) <= 64) {
                return $value;
            }
        }

        return null;
    }
}
