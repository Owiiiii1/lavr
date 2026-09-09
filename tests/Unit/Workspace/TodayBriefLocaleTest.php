<?php

namespace Tests\Unit\Workspace;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\UserAssistantProfile;
use App\Services\Workspace\TodayBriefService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class TodayBriefLocaleTest extends TestCase
{
    public function test_today_date_label_follows_owner_interface_locale(): void
    {
        $owner = $this->existingOwner();
        $profile = UserAssistantProfile::query()->firstOrCreate(
            ['user_id' => $owner->id],
            ['assistant_name' => 'LAVR'],
        );
        $originalInterface = $profile->interface_locale;
        $originalAssistant = $profile->assistant_locale;

        try {
            $this->travelTo(CarbonImmutable::parse('2026-09-09 12:00:00', $owner->timezone ?: 'UTC'));

            $profile->forceFill([
                'interface_locale' => 'en',
                'assistant_locale' => 'uk',
            ])->save();

            $english = app(TodayBriefService::class)->forUser($owner->fresh());

            $this->assertStringContainsString('September', $english['date_label']);
            $this->assertStringNotContainsString('сентябр', $english['date_label']);
            $this->assertStringNotContainsString('вересн', $english['date_label']);
            $this->assertStringNotContainsString('На сегодня', $english['summary']);
            $this->assertStringNotContainsString('На сьогодні', $english['summary']);

            $profile->forceFill(['interface_locale' => 'uk'])->save();
            $ukrainian = app(TodayBriefService::class)->forUser($owner->fresh());

            $this->assertStringContainsString('вересня', $ukrainian['date_label']);
            $this->assertStringNotContainsString('September', $ukrainian['date_label']);
        } finally {
            $this->travelBack();
            $profile->forceFill([
                'interface_locale' => $originalInterface,
                'assistant_locale' => $originalAssistant,
            ])->save();
        }
    }

    private function existingOwner(): User
    {
        $user = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($user, 'Today locale tests require an existing owner user.');

        return $user;
    }
}
