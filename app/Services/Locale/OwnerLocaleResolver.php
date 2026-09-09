<?php

namespace App\Services\Locale;

use App\Enums\OwnerLocale;
use App\Models\User;
use App\Models\UserAssistantProfile;

final class OwnerLocaleResolver
{
    public function interfaceLocale(?User $user): OwnerLocale
    {
        return OwnerLocale::fromMixed($this->profile($user)?->interface_locale);
    }

    public function assistantLocale(?User $user): OwnerLocale
    {
        return OwnerLocale::fromMixed($this->profile($user)?->assistant_locale);
    }

    private function profile(?User $user): ?UserAssistantProfile
    {
        if ($user === null) {
            return null;
        }

        if ($user->relationLoaded('assistantProfile')) {
            return $user->assistantProfile;
        }

        return $user->assistantProfile()->first();
    }
}
