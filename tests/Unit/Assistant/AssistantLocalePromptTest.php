<?php

namespace Tests\Unit\Assistant;

use App\Enums\OnboardingStatus;
use App\Models\UserAssistantProfile;
use App\Services\Assistant\AssistantProfileService;
use App\Services\ConversationIntelligence\PersonalityPresentationBuilder;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class AssistantLocalePromptTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_identity_context_includes_preferred_assistant_language_without_mutating_it(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            UserAssistantProfile::query()->create([
                'user_id' => $user->id,
                'assistant_name' => 'LAVR',
                'interface_locale' => 'en',
                'assistant_locale' => 'uk',
                'onboarding_status' => OnboardingStatus::Completed,
            ]);

            $prompt = app(AssistantProfileService::class)->identityContext($user);
            $profile = UserAssistantProfile::query()->where('user_id', $user->id)->first();

            $this->assertStringContainsString('Preferred assistant response language: Ukrainian (uk).', $prompt);
            $this->assertStringContainsString('Reply in this language by default.', $prompt);
            $this->assertStringContainsString('for this turn only', $prompt);
            $this->assertStringContainsString('Do not change the stored preferred assistant language', $prompt);
            $this->assertSame('en', $profile?->interface_locale);
            $this->assertSame('uk', $profile?->assistant_locale);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_conversation_prompt_keeps_ui_and_assistant_locales_independent(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            UserAssistantProfile::query()->create([
                'user_id' => $user->id,
                'assistant_name' => 'LAVR',
                'interface_locale' => 'en',
                'assistant_locale' => 'ru',
                'onboarding_status' => OnboardingStatus::Completed,
            ]);

            $prompt = (new PersonalityPresentationBuilder(app(AssistantProfileService::class)))->build($user);

            $this->assertStringContainsString('Preferred assistant response language: Russian (ru).', $prompt);
            $this->assertStringNotContainsString('Preferred assistant response language: English (en).', $prompt);
            $this->assertSame('en', UserAssistantProfile::query()->where('user_id', $user->id)->value('interface_locale'));
            $this->assertSame('ru', UserAssistantProfile::query()->where('user_id', $user->id)->value('assistant_locale'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
