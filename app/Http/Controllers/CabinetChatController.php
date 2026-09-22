<?php

namespace App\Http\Controllers;

use App\Services\Conversations\ConversationService;
use App\Services\Conversations\PersonalChatSurfaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class CabinetChatController extends Controller
{
    public function __construct(
        private readonly PersonalChatSurfaceService $chats,
    ) {}

    public function index(): RedirectResponse
    {
        return redirect()->route('jarvis.index');
    }

    public function show(Request $request, int $conversation): Response
    {
        $user = $request->user();
        $current = $this->chats->ensureOwned($user, $conversation);
        $page = $this->chats->page($current);

        return Inertia::render('Cabinet/Chat', [
            'user' => $this->chats->userProfile($user),
            'conversations' => $this->chats->sidebar($user, (int) $current->id),
            'conversation' => [
                'id' => $current->id,
                'title' => $current->title,
            ],
            'messages' => $page['messages'],
            'hasMore' => $page['has_more'],
            'oldestId' => $page['oldest_id'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $conversation = $this->chats->createChat($request->user());

        return redirect()->route('jarvis.chats.show', $conversation);
    }

    public function update(Request $request, int $conversation): RedirectResponse
    {
        $user = $request->user();
        $current = $this->chats->ensureOwned($user, $conversation);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:'.ConversationService::TITLE_MAX_LENGTH],
        ]);

        try {
            $this->chats->rename($user, $current, $validated['title']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'title' => $exception->getMessage(),
            ]);
        }

        return back();
    }

    public function storeMessage(Request $request, int $conversation): JsonResponse
    {
        $user = $request->user();
        $current = $this->chats->ensureOwned($user, $conversation);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
            'client_message_id' => ['required', 'uuid'],
        ]);

        return response()->json(
            $this->chats->sendTurn(
                $user,
                $current,
                $validated['body'],
                $validated['client_message_id'],
            ),
        );
    }

    public function messages(Request $request, int $conversation): JsonResponse
    {
        $current = $this->chats->ensureOwned($request->user(), $conversation);

        $validated = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->chats->page(
            $current,
            isset($validated['before_id']) ? (int) $validated['before_id'] : null,
            isset($validated['limit']) ? (int) $validated['limit'] : null,
        ));
    }
}
