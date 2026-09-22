<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SettingsNavigationTest extends TestCase
{
    public function test_settings_opens_on_the_overview_tab(): void
    {
        $owner = $this->existingOwner();

        $props = $this->inertiaProps($this->actingAs($owner)->get(route('settings.index')));

        $this->assertSame('overview', $props['tab']);
        $this->assertArrayNotHasKey('section', $props);
    }

    public function test_every_element_has_its_own_tab(): void
    {
        $owner = $this->existingOwner();

        foreach (['ai', 'telegram', 'google', 'zoom', 'voice', 'web-research', 'activity'] as $tab) {
            $props = $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', ['tab' => $tab])));

            $this->assertSame($tab, $props['tab']);
        }
    }

    public function test_legacy_links_resolve_to_flat_tabs(): void
    {
        $owner = $this->existingOwner();

        $this->assertSame(
            'voice',
            $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', [
                'tab' => 'integrations',
                'section' => 'voice',
            ])))['tab'],
        );

        $this->assertSame(
            'overview',
            $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', ['tab' => 'integrations'])))['tab'],
        );

        $this->assertSame(
            'overview',
            $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', ['tab' => 'general'])))['tab'],
        );

        $this->assertSame(
            'overview',
            $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', ['tab' => 'nope'])))['tab'],
        );

        $this->assertSame(
            'telegram',
            $this->inertiaProps($this->actingAs($owner)->get(route('settings.index', ['tab' => 'telegram'])))['tab'],
        );
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
        $this->assertNotNull($user, 'Settings navigation tests require an existing owner user.');

        return $user;
    }
}
