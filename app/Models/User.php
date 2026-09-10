<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Services\Users\UserCapabilities;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'access_code', 'status', 'timezone', 'voice_id'])]
#[Hidden(['password', 'remember_token', 'access_code'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function canUseCapability(string $capability): bool
    {
        return UserCapabilities::userCan($this, $capability);
    }

    public function channelIdentities(): HasMany
    {
        return $this->hasMany(ChannelIdentity::class);
    }

    public function telegramIdentity(): HasOne
    {
        return $this->hasOne(ChannelIdentity::class)->where('channel', ChannelIdentity::CHANNEL_TELEGRAM);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(Commitment::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function jarvisNotifications(): HasMany
    {
        return $this->hasMany(JarvisNotification::class);
    }

    public function productivitySetting(): HasOne
    {
        return $this->hasOne(UserProductivitySetting::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function aiSettings(): HasOne
    {
        return $this->hasOne(UserAiSetting::class);
    }

    public function memories(): HasMany
    {
        return $this->hasMany(Memory::class);
    }

    public function storedFiles(): HasMany
    {
        return $this->hasMany(StoredFile::class);
    }

    public function voiceSessions(): HasMany
    {
        return $this->hasMany(VoiceSession::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function conversationSummaries(): HasMany
    {
        return $this->hasMany(ConversationSummary::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function assistantProfile(): HasOne
    {
        return $this->hasOne(UserAssistantProfile::class);
    }

    public function channelPreferences(): HasMany
    {
        return $this->hasMany(UserChannelPreference::class);
    }
}
