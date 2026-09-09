<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Users\UserCapabilities;
use App\Services\Users\UserCapability;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserCapabilitiesTest extends TestCase
{
    public function test_owner_defaults_include_wildcard(): void
    {
        $defaults = UserCapabilities::defaultsForRole(UserRole::Owner);

        $this->assertSame(['*'], $defaults);
    }

    public function test_user_defaults_match_expected_capabilities(): void
    {
        $defaults = UserCapabilities::defaultsForRole(UserRole::User);

        $this->assertSame(UserCapability::forRegularUser(), $defaults);
    }

    #[DataProvider('userDeniedCapabilitiesProvider')]
    public function test_regular_user_denied_admin_capabilities(string $capability): void
    {
        $user = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($user);

        $user->role = UserRole::User;

        $this->assertFalse($user->canUseCapability($capability));
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function userDeniedCapabilitiesProvider(): array
    {
        return [
            [UserCapability::ADMIN],
            [UserCapability::USERS_ADMIN],
            [UserCapability::INTEGRATIONS_ADMIN],
            [UserCapability::TELEGRAM_GROUPS],
            [UserCapability::GROUP_ANALYSIS],
            [UserCapability::PROJECTS],
            [UserCapability::GMAIL],
            [UserCapability::GOOGLE_CALENDAR],
            [UserCapability::GITHUB],
            [UserCapability::IMPERSONATION],
            [UserCapability::SYSTEM_AI_SETTINGS],
        ];
    }
}
