<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBotManager;
use App\Services\Telegram\WebApp\TelegramWebAppAuthenticator;
use App\Services\Telegram\WebApp\TelegramWebAppAuthException;
use App\Services\Telegram\WebApp\TelegramWebAppInitDataValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class TelegramWebAppController extends Controller
{
    public function __construct(
        private readonly TelegramWebAppAuthenticator $authenticator,
        private readonly TelegramBotManager $bots,
        private readonly TelegramWebAppInitDataValidator $validator,
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
            'init_data' => ['required', 'string', 'max:16384'],
            'start_param' => ['nullable', 'string', 'max:64'],
            'next' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->authenticator->authenticate(
                $request,
                $this->validator->coalesce((string) $validated['init_data'], $request->all()),
                isset($validated['start_param']) ? (string) $validated['start_param'] : null,
                isset($validated['next']) ? (string) $validated['next'] : null,
            );
        } catch (TelegramWebAppAuthException $exception) {
            if ($exception->reason !== 'invalid' && $exception->reason !== 'expired') {
                Log::info('telegram_webapp_auth', [
                    'outcome' => $exception->reason,
                ]);
            }

            return Inertia::render('Telegram/WebAppBlocked', $this->blockedProps($exception->reason));
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

    /**
     * @return array{reason: string, bot_username: ?string, bot_chat_href: ?string}
     */
    private function blockedProps(string $reason): array
    {
        $username = ltrim((string) ($this->bots->existingSetting()?->bot_username ?? ''), '@');

        return [
            'reason' => $reason,
            'bot_username' => $username !== '' ? $username : null,
            'bot_chat_href' => $username !== '' ? 'https://t.me/'.$username : null,
        ];
    }
}
