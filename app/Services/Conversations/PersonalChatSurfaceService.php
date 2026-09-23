<?php

namespace App\Services\Conversations;

use App\Enums\MessageChannel;
use App\Models\Conversation;
use App\Models\Person;
use App\Models\ToolConfirmation;
use App\Models\User;
use App\Services\Assistant\AssistantProfileService;
use App\Services\ChatAttachments\Exceptions\ChatAttachmentException;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Storage\Exceptions\StoredFileException;
use App\Services\Tools\ToolConfirmationService;
use App\Services\Users\UserCapability;
use App\Services\Voice\VoiceSettingsService;
use App\Services\Workspace\WorkspaceSurfaceStateService;
use App\Support\Timezones;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PersonalChatSurfaceService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly ConversationTurnService $turns,
        private readonly MessageHistoryService $history,
        private readonly ToolConfirmationService $confirmations,
        private readonly AssistantProfileService $assistantProfiles,
        private readonly ConversationAiService $conversationAi,
        private readonly VoiceSettingsService $voiceSettings,
        private readonly ProductivitySettingsService $productivity,
        private readonly WorkspaceSurfaceStateService $workspaceState,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function sidebar(User $user, ?int $currentId = null, int $limit = ConversationService::CABINET_LIST_LIMIT): array
    {
        return $this->conversations->listForUser($user, $limit)
            ->map(static fn ($conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                'current' => $currentId !== null && (int) $conversation->id === $currentId,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function userProfile(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'timezone' => $user->timezone,
        ];
    }

    /**
     * Presentation flags only. Backend capability and ownership checks are authoritative.
     *
     * @return array<string, bool>
     */
    public function uiCapabilities(User $user): array
    {
        $owner = $user->isOwner();

        return [
            'voice' => $user->canUseCapability(UserCapability::VOICE),
            'webResearch' => $user->canUseCapability(UserCapability::WEB_RESEARCH),
            'attachments' => $user->canUseCapability(UserCapability::CHAT),
            'files' => $user->canUseCapability(UserCapability::STORAGE),
            'projects' => $user->canUseCapability(UserCapability::PROJECTS),
            'admin' => $owner,
            'integrations' => $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN),
            'storagePage' => $owner,
            'ownerContext' => $owner,
            'reminders' => $user->canUseCapability(UserCapability::REMINDERS),
            'tasks' => $user->canUseCapability(UserCapability::TASKS),
            'notifications' => $user->canUseCapability(UserCapability::NOTIFICATIONS),
            'memory' => $user->canUseCapability(UserCapability::MEMORY),
            'knowledge' => $user->canUseCapability(UserCapability::KNOWLEDGE),
            'watchers' => $user->canUseCapability(UserCapability::WATCHERS),
            'scheduledReports' => $user->canUseCapability(UserCapability::SCHEDULED_REPORTS),
            'telegramDm' => $user->canUseCapability(UserCapability::TELEGRAM_DM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function personalSettings(User $user): array
    {
        $user->loadMissing('aiSettings');

        return [
            'name' => $user->name,
            'timezone' => $user->timezone,
            'general_prompt' => $user->aiSettings?->general_prompt,
            'timezones' => Timezones::options($user->timezone),
            'voice' => $this->voiceSettings->userVoicePayload($user),
            'productivity' => $this->productivity->payload($user),
            'review_people' => Person::query()->where('user_id', $user->id)->orderBy('display_name')->get(['id', 'display_name']),
        ];
    }

    public function ensureOwned(User $user, int $conversationId): Conversation
    {
        return $this->conversations->ensureOwned($user, $conversationId);
    }

    public function latestOrDefault(User $user): Conversation
    {
        return $this->conversations->latestOrDefault($user);
    }

    public function createChat(User $user): Conversation
    {
        return $this->conversations->createPersonal($user, ConversationService::NEW_CHAT_TITLE);
    }

    public function startOnboarding(User $user): Conversation
    {
        $conversation = $this->assistantProfiles->startOnboarding($user);

        try {
            $this->conversationAi->greetOnboarding($user, $conversation);
        } catch (\Throwable) {
        }

        return $conversation->fresh() ?? $conversation;
    }

    public function rename(User $user, Conversation $conversation, string $title): Conversation
    {
        return $this->conversations->rename($user, $conversation, $title);
    }

    /**
     * @return array{success: true, deleted_id: int, conversation: array{id: int, title: string, last_activity_at: string|null}}
     */
    public function deleteChat(User $user, Conversation $conversation): array
    {
        return $this->conversations->deletePersonal($user, $conversation);
    }

    /**
     * @return array{messages: list<array<string, mixed>>, has_more: bool, oldest_id: int|null}
     */
    public function page(Conversation $conversation, ?int $beforeId = null, ?int $limit = null): array
    {
        return $this->history->page(
            $conversation,
            $beforeId,
            $limit ?? MessageHistoryService::PAGE_SIZE,
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<UploadedFile>  $documents
     * @return array<string, mixed>
     */
    public function sendTurn(
        User $user,
        Conversation $conversation,
        string $body,
        string $clientMessageId,
        array $files = [],
        array $documents = [],
    ): array {
        try {
            $turn = $this->turns->handleUserMessage(
                $user,
                $conversation,
                $body,
                new ChannelContext(
                    channel: MessageChannel::Web,
                    channelMessageId: $clientMessageId,
                ),
                $files,
                $documents,
            );
        } catch (AuthorizationException) {
            abort(404);
        } catch (ChatAttachmentException $exception) {
            throw ValidationException::withMessages([
                'images' => $exception->getMessage(),
            ]);
        } catch (StoredFileException $exception) {
            throw ValidationException::withMessages([
                'files' => $exception->getMessage(),
            ]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'body' => $exception->getMessage(),
            ]);
        }

        return $this->turnPayload($turn, $conversation);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveConfirmation(
        User $user,
        string $publicId,
        bool $confirm,
        string $clientMessageId,
    ): array {
        $row = $this->confirmations->findOwnedByPublicId($user, $publicId);

        if ($row === null) {
            abort(404);
        }

        $conversation = $this->conversations->ensureOwned($user, (int) $row->conversation_id);
        $this->confirmations->expireStale($user, $conversation);
        $row = $row->fresh() ?? $row;

        if (! $row->isPending()) {
            return $this->alreadyResolvedPayload($row, $conversation);
        }

        $latest = $this->confirmations->latestPending($user, $conversation);

        if ($latest === null || $latest->public_id !== $row->public_id) {
            return $this->alreadyResolvedPayload($row, $conversation);
        }

        $payload = $this->sendTurn(
            $user,
            $conversation,
            $confirm ? 'да' : 'отмена',
            $clientMessageId,
        );
        $resolved = $this->confirmations->findOwnedByPublicId($user, $publicId) ?? $row;
        $payload['already_resolved'] = false;
        $payload['confirmation'] = $this->history->confirmationCard(
            $resolved->public_id,
            null,
            $resolved,
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function alreadyResolvedPayload(ToolConfirmation $row, Conversation $conversation): array
    {
        $fresh = $conversation->fresh() ?? $conversation;
        $fresh->loadMissing('user');

        return [
            'already_resolved' => true,
            'confirmation' => $this->history->confirmationCard($row->public_id, null, $row),
            'inbound' => null,
            'assistant' => null,
            'error' => null,
            'duplicate' => false,
            'conversation' => [
                'id' => $fresh->id,
                'title' => $fresh->title,
                'last_activity_at' => optional($fresh->last_activity_at)?->toIso8601String(),
            ],
            'assistant_profile' => $this->assistantProfiles->workspacePayload($fresh->user),
            ...$this->workspaceState->turnCounts($fresh->user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function turnPayload(ConversationTurnResult $turn, Conversation $conversation): array
    {
        $fresh = $conversation->fresh() ?? $conversation;
        $fresh->loadMissing('user');
        $user = $fresh->user;

        return [
            'inbound' => $this->history->toArray($turn->inbound),
            'assistant' => $turn->assistantMessage !== null
                ? $this->history->toArray($turn->assistantMessage)
                : null,
            'error' => $turn->errorText,
            'duplicate' => ! $turn->created,
            'conversation' => [
                'id' => $fresh->id,
                'title' => $fresh->title,
                'last_activity_at' => optional($fresh->last_activity_at)?->toIso8601String(),
            ],
            'assistant_profile' => $this->assistantProfiles->workspacePayload($user),
            ...$this->workspaceState->turnCounts($user),
        ];
    }
}
