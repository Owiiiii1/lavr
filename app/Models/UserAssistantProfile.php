<?php

namespace App\Models;

use App\Enums\OnboardingStatus;
use App\Enums\OnboardingStep;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'assistant_name',
    'personality',
    'interaction_style',
    'about_user',
    'interface_locale',
    'assistant_locale',
    'onboarding_status',
    'onboarding_step',
    'onboarding_conversation_id',
    'onboarding_started_at',
    'onboarding_completed_at',
])]
class UserAssistantProfile extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'onboarding_status' => OnboardingStatus::class,
            'onboarding_step' => OnboardingStep::class,
            'onboarding_started_at' => 'immutable_datetime',
            'onboarding_completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function onboardingConversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'onboarding_conversation_id');
    }
}
