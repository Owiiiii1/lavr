<?php

namespace App\Services\Telegram\WebApp;

use App\Enums\UserRole;
use App\Models\ChannelIdentity;
use App\Models\User;
use App\Services\Telegram\TelegramBotManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

final class TelegramWebAppAuthenticator
{
    public function __construct(
        private readonly TelegramWebAppInitDataValidator $validator,
        private readonly TelegramBotManager $bots,
        private readonly TelegramWebAppDeepLink $deepLinks,
    ) {}

    /**
     * @return array{user: User, path: string}
     */
    public function authenticate(Request $request, string $initData, ?string $startParam, ?string $next): array
    {
        $token = trim((string) $this->bots->setting()->bot_token);

        if ($token === '') {
            throw TelegramWebAppAuthException::unavailable();
        }

        $maxAge = max(60, (int) config('telegram.webapp.auth_max_age', 86400));
        $telegramUser = $this->validator->validate($initData, $token, $maxAge);

        $identity = ChannelIdentity::findTelegramByExternalUserId($telegramUser->telegramUserId);

        if ($identity === null) {
            $this->logOutcome('not_linked', $telegramUser->telegramUserId);
            throw TelegramWebAppAuthException::notLinked();
        }

        $user = User::query()->find($identity->user_id);

        if ($user === null || ! $user->isActive() || $user->role !== UserRole::Owner) {
            $this->logOutcome('not_linked', $telegramUser->telegramUserId);
            throw TelegramWebAppAuthException::notLinked();
        }

        Auth::login($user);
        $request->session()->regenerate();

        $identity->forceFill([
            'last_seen_at' => now(),
            'username' => $telegramUser->username ?: $identity->username,
            'first_name' => $telegramUser->firstName ?: $identity->first_name,
            'last_name' => $telegramUser->lastName ?: $identity->last_name,
        ])->save();

        $path = $this->deepLinks->resolve(
            $telegramUser->startParam ?? $startParam,
            $next,
        );

        $this->logOutcome('ok', $telegramUser->telegramUserId);

        return [
            'user' => $user,
            'path' => $path,
        ];
    }

    private function logOutcome(string $outcome, string $telegramUserId): void
    {
        Log::info('telegram_webapp_auth', [
            'outcome' => $outcome,
            'telegram_user_id' => $telegramUserId,
        ]);
    }
}
