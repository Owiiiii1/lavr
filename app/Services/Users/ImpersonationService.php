<?php

namespace App\Services\Users;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated LAVR is a single-client instance. Impersonation routes were
 * removed from the product surface in Phase 1.
 */
final class ImpersonationService
{
    public const OWNER_ID = 'impersonation.original_owner_user_id';

    public const TARGET_ID = 'impersonation.impersonated_user_id';

    public const STARTED_AT = 'impersonation.started_at';

    public function start(Request $request, User $owner, User $target): void
    {
        if ($this->isActive($request)) {
            abort(409);
        }

        if (! $owner->isOwner() || ! $owner->canUseCapability(UserCapability::IMPERSONATION)) {
            abort(403);
        }

        if ($target->role !== UserRole::User || ! $target->isActive()) {
            abort(403);
        }

        if ((int) $owner->id === (int) $target->id) {
            abort(403);
        }

        $request->session()->put(self::OWNER_ID, (int) $owner->id);
        $request->session()->put(self::TARGET_ID, (int) $target->id);
        $request->session()->put(self::STARTED_AT, now()->toIso8601String());

        Auth::guard('web')->login($target, false);
        $request->session()->regenerate();

        $request->session()->put(self::OWNER_ID, (int) $owner->id);
        $request->session()->put(self::TARGET_ID, (int) $target->id);
        $request->session()->put(self::STARTED_AT, now()->toIso8601String());

        Log::info('impersonation_started', [
            'owner_user_id' => (int) $owner->id,
            'target_user_id' => (int) $target->id,
        ]);
    }

    public function stop(Request $request): ?User
    {
        $ownerId = (int) $request->session()->get(self::OWNER_ID, 0);
        $targetId = (int) $request->session()->get(self::TARGET_ID, 0);

        $this->forget($request);

        if ($ownerId < 1) {
            return null;
        }

        $owner = User::query()->find($ownerId);

        if ($owner === null || ! $owner->isOwner() || ! $owner->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            Log::info('impersonation_ended', [
                'owner_user_id' => $ownerId,
                'target_user_id' => $targetId,
                'restored' => false,
            ]);

            return null;
        }

        Auth::guard('web')->login($owner, false);
        $request->session()->regenerate();
        $this->forget($request);

        Log::info('impersonation_ended', [
            'owner_user_id' => (int) $owner->id,
            'target_user_id' => $targetId,
            'restored' => true,
        ]);

        return $owner;
    }

    public function isActive(Request $request): bool
    {
        return (int) $request->session()->get(self::OWNER_ID, 0) > 0
            && (int) $request->session()->get(self::TARGET_ID, 0) > 0;
    }

    /**
     * @return array{active: bool, user_id: int|null, user_name: string|null, started_at: string|null}
     */
    public function banner(Request $request): array
    {
        if (! $this->isActive($request)) {
            return [
                'active' => false,
                'user_id' => null,
                'user_name' => null,
                'started_at' => null,
            ];
        }

        $target = $request->user();

        return [
            'active' => true,
            'user_id' => (int) $request->session()->get(self::TARGET_ID),
            'user_name' => $target?->name,
            'started_at' => $request->session()->get(self::STARTED_AT),
        ];
    }

    public function targetId(Request $request): ?int
    {
        $id = (int) $request->session()->get(self::TARGET_ID, 0);

        return $id > 0 ? $id : null;
    }

    private function forget(Request $request): void
    {
        $request->session()->forget([
            self::OWNER_ID,
            self::TARGET_ID,
            self::STARTED_AT,
        ]);
    }
}
