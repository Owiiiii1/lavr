<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserAssistantProfile;
use App\Services\Assistant\AssistantProfileService;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OwnerLocalePreferenceTest extends TestCase
{
    public function test_guest_sees_uk_as_the_shared_default_locale(): void
    {
        $login = $this->inertiaProps($this->get('/'));
        $webapp = $this->inertiaProps($this->get('/telegram/webapp'));

        $this->assertSame('uk', $login['locale']);
        $this->assertSame('uk', $login['assistantLocale']);
        $this->assertSame(['uk', 'en', 'ru'], $login['supportedLocales']);
        $this->assertSame('uk', $webapp['locale']);
        $this->assertSame('uk', $webapp['assistantLocale']);
        $this->get('/register')->assertNotFound();
    }

    public function test_guest_cannot_save_locale_preferences(): void
    {
        $this->patch(route('jarvis.settings.locales.update'), [
            'interface_locale' => 'en',
            'assistant_locale' => 'ru',
        ])->assertRedirect(route('login'));
    }

    public function test_owner_can_save_interface_and_assistant_locales_separately(): void
    {
        $owner = $this->existingOwner();

        $this->withRestoredLocales($owner, function (User $owner): void {
            $this->actingAs($owner)
                ->from(route('jarvis.today.show'))
                ->patch(route('jarvis.settings.locales.update'), [
                    'interface_locale' => 'en',
                    'assistant_locale' => 'uk',
                ])
                ->assertRedirect(route('jarvis.today.show'));

            $profile = UserAssistantProfile::query()->where('user_id', $owner->id)->first();
            $this->assertNotNull($profile);
            $this->assertSame('en', $profile->interface_locale);
            $this->assertSame('uk', $profile->assistant_locale);

            $today = $this->inertiaProps($this->actingAs($owner)->get(route('jarvis.today.show')));
            $webapp = $this->inertiaProps($this->actingAs($owner)->get(route('telegram.webapp.show')));

            $this->assertSame('en', $today['locale']);
            $this->assertSame('uk', $today['assistantLocale']);
            $this->assertSame(['uk', 'en', 'ru'], $today['supportedLocales']);
            $this->assertSame('en', $webapp['locale']);
            $this->assertSame('uk', $webapp['assistantLocale']);
            $this->assertSame('en', $this->inertiaProps($this->actingAs($owner)->get(route('jarvis.today.show')))['locale']);
        });
    }

    public function test_admin_language_switch_updates_interface_locale_only(): void
    {
        $owner = $this->existingOwner();

        $this->withRestoredLocales($owner, function (User $owner): void {
            app(AssistantProfileService::class)->updateLocales($owner, 'uk', 'en');

            $this->actingAs($owner)
                ->from(route('settings.index'))
                ->post(route('settings.language.update'), ['locale' => 'ru'])
                ->assertRedirect(route('settings.index'));

            $profile = UserAssistantProfile::query()->where('user_id', $owner->id)->first();
            $this->assertSame('ru', $profile?->interface_locale);
            $this->assertSame('en', $profile?->assistant_locale);

            $settings = $this->inertiaProps($this->actingAs($owner)->get(route('settings.index')));
            $this->assertSame('ru', $settings['locale']);
            $this->assertSame('en', $settings['assistantLocale']);
        });
    }

    public function test_unsupported_locale_is_stored_as_uk(): void
    {
        $owner = $this->existingOwner();

        $this->withRestoredLocales($owner, function (User $owner): void {
            $this->actingAs($owner)->patch(route('jarvis.settings.locales.update'), [
                'interface_locale' => 'fr',
                'assistant_locale' => 'zh',
            ]);

            $profile = UserAssistantProfile::query()->where('user_id', $owner->id)->first();
            $this->assertSame('uk', $profile?->interface_locale);
            $this->assertSame('uk', $profile?->assistant_locale);

            $props = $this->inertiaProps($this->actingAs($owner)->get(route('jarvis.more.show')));
            $this->assertSame('uk', $props['locale']);
            $this->assertSame('uk', $props['assistantLocale']);
        });
    }

    public function test_missing_stored_locale_falls_back_to_uk_without_writing(): void
    {
        $owner = $this->existingOwner();

        $this->withRestoredLocales($owner, function (User $owner): void {
            $profile = app(AssistantProfileService::class)->profileFor($owner, persist: true);
            $profile->forceFill([
                'interface_locale' => null,
                'assistant_locale' => null,
            ])->save();

            $props = $this->inertiaProps($this->actingAs($owner)->get(route('jarvis.today.show')));
            $fresh = $profile->fresh();

            $this->assertSame('uk', $props['locale']);
            $this->assertSame('uk', $props['assistantLocale']);
            $this->assertNull($fresh?->interface_locale);
            $this->assertNull($fresh?->assistant_locale);
        });
    }

    public function test_building_assistant_context_does_not_change_stored_locale(): void
    {
        $owner = $this->existingOwner();

        $this->withRestoredLocales($owner, function (User $owner): void {
            app(AssistantProfileService::class)->updateLocales($owner, 'en', 'uk');

            $prompt = app(AssistantProfileService::class)->identityContext($owner);
            $profile = UserAssistantProfile::query()->where('user_id', $owner->id)->first();

            $this->assertStringContainsString('Preferred assistant response language: Ukrainian (uk).', $prompt);
            $this->assertSame('en', $profile?->interface_locale);
            $this->assertSame('uk', $profile?->assistant_locale);
        });
    }

    /**
     * @param  callable(User): void  $callback
     */
    private function withRestoredLocales(User $owner, callable $callback): void
    {
        $profile = app(AssistantProfileService::class)->profileFor($owner, persist: true);
        $interface = $profile->interface_locale;
        $assistant = $profile->assistant_locale;

        try {
            $callback($owner);
        } finally {
            $profile->fresh()?->forceFill([
                'interface_locale' => $interface,
                'assistant_locale' => $assistant,
            ])->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps(TestResponse $response): array
    {
        $response->assertOk();
        $html = $response->getContent();
        $marker = '<script data-page="app" type="application/json">';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start, 'Inertia page JSON script is missing.');
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        $this->assertNotFalse($end, 'Inertia page JSON script is not closed.');
        $page = json_decode(substr($html, $start, $end - $start), true);
        $this->assertIsArray($page);
        $this->assertIsArray($page['props'] ?? null);

        return $page['props'];
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($user, 'Locale preference tests require an existing owner user.');

        return $user;
    }
}
