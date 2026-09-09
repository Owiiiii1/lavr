<?php

namespace Tests\Feature\Http\Controllers\Jarvis;

use App\Services\Voice\VoiceSettingsService;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class JarvisWorkspaceControllerTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_personal_settings_offer_three_female_and_three_male_voices(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $voices = app(VoiceSettingsService::class)->userVoicePayload($user)['voices'];

            $this->assertCount(6, $voices);
            $this->assertSame(3, count(array_filter($voices, fn (array $voice): bool => $voice['gender'] === 'female')));
            $this->assertSame(3, count(array_filter($voices, fn (array $voice): bool => $voice['gender'] === 'male')));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_user_can_select_a_personal_voice_without_changing_another_user(): void
    {
        $firstUser = null;
        $secondUser = null;

        try {
            $firstUser = $this->createTemporaryUser();
            $secondUser = $this->createTemporaryUser();
            $secondUser->forceFill(['voice_id' => 'cjVigY5qzO86Huf0OWal'])->save();

            $response = $this->actingAs($firstUser)->patch(route('jarvis.settings.profile.update'), [
                'name' => $firstUser->name,
                'timezone' => $firstUser->timezone,
                'voice_id' => 'cgSgspJ2msm6clMCkdW9',
            ]);

            $response->assertRedirect();
            $this->assertSame('cgSgspJ2msm6clMCkdW9', $firstUser->fresh()->voice_id);
            $this->assertSame('cjVigY5qzO86Huf0OWal', $secondUser->fresh()->voice_id);
        } finally {
            $this->deleteTemporaryUser($firstUser);
            $this->deleteTemporaryUser($secondUser);
        }
    }

    public function test_user_cannot_select_a_voice_outside_the_curated_catalog(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();

            $response = $this->actingAs($user)->patch(route('jarvis.settings.profile.update'), [
                'name' => $user->name,
                'timezone' => $user->timezone,
                'voice_id' => 'unlisted-voice',
            ]);

            $response->assertSessionHasErrors('voice_id');
            $this->assertNull($user->fresh()->voice_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }
}
