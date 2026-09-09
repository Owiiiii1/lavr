<?php

namespace App\Services\Users;

use App\Enums\ConversationKind;
use App\Enums\MemoryStatus;
use App\Enums\StoredFileStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Services\Assistant\AssistantProfileService;
use App\Support\Timezones;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @deprecated LAVR is a single-client instance. Product-level user catalog
 * create/update/impersonate was removed from routes in Phase 1.
 */
final class UserAdministrationService
{
    public function __construct(
        private readonly AccessCodeGenerator $accessCodes,
        private readonly UserSessionInvalidator $sessions,
        private readonly AssistantProfileService $assistantProfiles,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function catalog(): array
    {
        return $this->catalogQuery()
            ->get()
            ->map(fn (User $user): array => $this->listRow($user))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function card(User $user): array
    {
        $user->loadMissing('telegramIdentity', 'aiSettings', 'assistantProfile');
        $user->loadCount([
            'conversations',
            'messages',
            'reminders',
            'memories',
            'voiceSessions',
            'storedFiles as stored_files_count' => static fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('status', '!=', StoredFileStatus::Deleted->value),
        ]);
        $user->loadMax('conversations', 'last_activity_at');

        $chats = Conversation::query()
            ->where('user_id', $user->id)
            ->where('kind', ConversationKind::Personal)
            ->withCount('messages')
            ->orderByRaw('last_activity_at IS NULL')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'last_activity_at', 'updated_at']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'timezone' => $user->timezone,
            'access_code' => $user->access_code,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'last_activity_at' => $this->lastActivityIso($user),
            'general_prompt' => $user->aiSettings?->general_prompt,
            'assistant_profile' => $this->assistantProfiles->workspacePayload($user),
            'is_owner' => $user->isOwner(),
            'can_disable' => ! $user->isOwner(),
            'can_regenerate_code' => ! $user->isOwner(),
            'can_impersonate' => $user->role === UserRole::User && $user->isActive(),
            'telegram' => $this->telegramPayload($user),
            'counts' => [
                'chats' => (int) $user->conversations_count,
                'messages' => (int) $user->messages_count,
                'memories' => (int) $user->memories_count,
                'stored_files' => (int) $user->stored_files_count,
                'reminders' => (int) $user->reminders_count,
                'voice_sessions' => (int) $user->voice_sessions_count,
                'active_memories' => Memory::query()
                    ->where('user_id', $user->id)
                    ->where('status', MemoryStatus::Active)
                    ->count(),
            ],
            'chats' => $chats->map(static fn (Conversation $conversation): array => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'last_activity_at' => optional($conversation->last_activity_at)?->toIso8601String(),
                'messages_count' => (int) $conversation->messages_count,
            ])->all(),
            'timezones' => Timezones::options($user->timezone),
        ];
    }

    public function create(array $attributes): User
    {
        return User::query()->create([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'password' => $attributes['password'],
            'role' => UserRole::User,
            'access_code' => $this->accessCodes->generate(),
            'status' => UserStatus::Active,
            'timezone' => $attributes['timezone'] ?? 'Europe/Rome',
        ]);
    }

    public function updateProfile(User $user, array $attributes): User
    {
        $user->fill([
            'name' => $attributes['name'],
            'email' => $attributes['email'],
            'timezone' => $attributes['timezone'],
        ]);
        $user->save();

        return $user;
    }

    public function setStatus(User $user, UserStatus $status): User
    {
        if ($user->isOwner()) {
            abort(403);
        }

        $user->forceFill(['status' => $status])->save();

        if ($status === UserStatus::Disabled) {
            $this->sessions->invalidate($user);
        }

        return $user;
    }

    public function setPassword(User $user, string $password): void
    {
        if ($user->isOwner()) {
            abort(403);
        }

        $user->forceFill(['password' => $password])->save();
        $this->sessions->invalidate($user);
    }

    public function updateGeneralPrompt(User $user, ?string $prompt): void
    {
        $value = is_string($prompt) ? trim($prompt) : null;

        UserAiSetting::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['general_prompt' => $value === '' ? null : $value],
        );
    }

    public function regenerateAccessCode(User $user): User
    {
        if ($user->isOwner()) {
            abort(403);
        }

        $user->forceFill([
            'access_code' => $this->accessCodes->generate(),
        ])->save();

        return $user;
    }

    private function catalogQuery()
    {
        return User::query()
            ->with('telegramIdentity')
            ->withCount(['conversations', 'messages'])
            ->withMax('conversations', 'last_activity_at')
            ->orderBy('name');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'status' => $user->status->value,
            'access_code' => $user->access_code,
            'timezone' => $user->timezone,
            'created_at' => optional($user->created_at)?->toIso8601String(),
            'last_activity_at' => $this->lastActivityIso($user),
            'chats_count' => (int) $user->conversations_count,
            'messages_count' => (int) $user->messages_count,
            'telegram' => $this->telegramPayload($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramPayload(User $user): array
    {
        $identity = $user->telegramIdentity;

        return [
            'connected' => $identity !== null,
            'username' => $identity?->username,
            'display_name' => trim(implode(' ', array_filter([
                $identity?->first_name,
                $identity?->last_name,
            ]))) ?: null,
            'linked_at' => optional($identity?->linked_at)?->toIso8601String(),
            'last_seen_at' => optional($identity?->last_seen_at)?->toIso8601String(),
        ];
    }

    private function lastActivityIso(User $user): ?string
    {
        $candidates = Collection::make([
            $user->conversations_max_last_activity_at ?? null,
            $user->telegramIdentity?->last_seen_at,
            $user->updated_at,
        ])->filter()->map(function ($value): Carbon {
            return $value instanceof Carbon ? $value : Carbon::parse((string) $value);
        });

        if ($candidates->isEmpty()) {
            return optional($user->created_at)?->toIso8601String();
        }

        return $candidates->sortByDesc(fn (Carbon $value): int => $value->getTimestamp())->first()?->toIso8601String();
    }
}
