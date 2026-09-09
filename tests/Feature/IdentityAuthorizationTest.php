<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Models\UserAiSetting;
use App\Services\Users\AccessCodeGenerator;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityAuthorizationTest extends TestCase
{
    public function test_users_table_has_identity_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['role', 'access_code', 'status', 'timezone']));
    }

    public function test_owner_can_access_dashboard_and_settings(): void
    {
        $owner = $this->existingOwner();

        $this->actingAs($owner)->get('/dashboard')->assertOk();
        $this->actingAs($owner)->get('/settings')->assertOk();
    }

    public function test_regular_user_is_forbidden_from_admin_routes(): void
    {
        $temporaryUser = null;

        try {
            $temporaryUser = $this->createTemporaryUser();

            $this->actingAs($temporaryUser)->get('/dashboard')->assertForbidden();
            $this->actingAs($temporaryUser)->get('/settings')->assertForbidden();
            $this->actingAs($temporaryUser)->get('/settings?tab=ai')->assertForbidden();
            $this->actingAs($temporaryUser)->get('/calendar')->assertForbidden();
            $this->actingAs($temporaryUser)->get('/statistics/logs')->assertForbidden();
            $this->actingAs($temporaryUser)->get('/cabinet')->assertRedirect();
            $this->actingAs($temporaryUser)->get('/cabinet/ai-settings')->assertRedirect(route('jarvis.index'));
        } finally {
            $this->deleteTemporaryUser($temporaryUser);
        }
    }

    public function test_regular_user_can_access_cabinet(): void
    {
        $temporaryUser = null;

        try {
            $temporaryUser = $this->createTemporaryUser();

            $this->actingAs($temporaryUser)->get('/cabinet')->assertRedirect();
        } finally {
            $this->deleteTemporaryUser($temporaryUser);
        }
    }

    public function test_disabled_user_session_is_invalidated_on_request(): void
    {
        $temporaryUser = null;

        try {
            $temporaryUser = $this->createTemporaryUser();
            $temporaryUser->status = UserStatus::Disabled;
            $temporaryUser->save();

            $this->actingAs($temporaryUser)
                ->get('/cabinet')
                ->assertRedirect(route('login'));
        } finally {
            $this->deleteTemporaryUser($temporaryUser);
        }
    }

    public function test_exactly_one_owner_exists_with_reserved_code(): void
    {
        $owners = User::query()->where('role', UserRole::Owner)->get();

        $this->assertCount(1, $owners);
        $this->assertSame(AccessCodeGenerator::OWNER_CODE, $owners->first()->access_code);
        $this->assertSame('Europe/Rome', $owners->first()->timezone);
    }

    private function existingOwner(): User
    {
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner, 'Identity tests require an existing owner user.');

        return $owner;
    }

    private function createTemporaryUser(): User
    {
        $generator = app(AccessCodeGenerator::class);

        return User::query()->create([
            'name' => 'Jarvis Test User',
            'email' => 'jarvis-test-'.Str::lower(Str::random(12)).'@invalid.local',
            'password' => Hash::make('temporary-test-password'),
            'role' => UserRole::User,
            'access_code' => $generator->generate(),
            'status' => UserStatus::Active,
            'timezone' => 'Europe/Rome',
        ]);
    }

    private function deleteTemporaryUser(?User $user): void
    {
        if ($user === null) {
            return;
        }

        if (! str_contains($user->email, '@invalid.local') || ! str_starts_with($user->email, 'jarvis-test-')) {
            return;
        }

        UserAiSetting::query()->where('user_id', $user->id)->delete();
        User::query()->whereKey($user->id)->delete();
    }
}
