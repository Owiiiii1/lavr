<?php

namespace App\Http\Controllers\Jarvis;

use App\Http\Controllers\Controller;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Users\UserCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class JarvisNotificationController extends Controller
{
    public function __construct(
        private readonly JarvisNotificationService $inbox,
    ) {}

    public function index(Request $request): JsonResponse|Response
    {
        $user = $request->user();
        $this->assertInbox($user);

        if ($this->wantsJsonPanel($request)) {
            return response()->json($this->inbox->panelFor($user, $request->boolean('unread')));
        }

        return Inertia::render('Jarvis/Notifications', [
            'inbox' => $this->inbox->panelFor($user, false),
        ]);
    }

    public function markRead(Request $request, int $notification): JsonResponse
    {
        $user = $request->user();
        $this->assertInbox($user);
        $this->inbox->markReadOwned($user, $notification);

        return response()->json([
            'ok' => true,
            ...$this->inbox->panelFor($user, $request->boolean('unread')),
        ]);
    }

    public function dismiss(Request $request, int $notification): JsonResponse
    {
        $user = $request->user();
        $this->assertInbox($user);
        $this->inbox->dismissOwned($user, $notification);

        return response()->json([
            'ok' => true,
            ...$this->inbox->panelFor($user, $request->boolean('unread')),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->assertInbox($user);
        $this->inbox->markAllRead($user);

        return response()->json([
            'ok' => true,
            ...$this->inbox->panelFor($user),
        ]);
    }

    private function assertInbox($user): void
    {
        if ($user === null || ! $user->isActive() || ! $user->canUseCapability(UserCapability::NOTIFICATIONS)) {
            abort(403);
        }
    }

    private function wantsJsonPanel(Request $request): bool
    {
        return $request->expectsJson() && $request->header('X-Inertia') === null;
    }
}
