<?php

namespace Tests\Unit\Enums;

use App\Enums\OwnerLocale;
use Tests\TestCase;

class OwnerLocaleTest extends TestCase
{
    public function test_default_locale_is_uk(): void
    {
        $this->assertSame('uk', OwnerLocale::default()->value);
        $this->assertSame('uk', config('app.locale'));
        $this->assertSame('uk', config('app.fallback_locale'));
        $this->assertSame('uk', config('locale.default'));
        $this->assertSame('uk', config('locale.fallback'));
    }

    public function test_supported_locales_are_uk_en_and_ru(): void
    {
        $this->assertSame(['uk', 'en', 'ru'], OwnerLocale::codes());
        $this->assertSame(['uk', 'en', 'ru'], config('locale.supported'));
    }

    public function test_unsupported_locale_falls_back_to_uk(): void
    {
        $this->assertSame(OwnerLocale::Uk, OwnerLocale::fromMixed(null));
        $this->assertSame(OwnerLocale::Uk, OwnerLocale::fromMixed(''));
        $this->assertSame(OwnerLocale::Uk, OwnerLocale::fromMixed('fr'));
        $this->assertSame(OwnerLocale::Uk, OwnerLocale::fromMixed('UK'));
        $this->assertSame(OwnerLocale::En, OwnerLocale::fromMixed('en'));
        $this->assertSame(OwnerLocale::Ru, OwnerLocale::fromMixed('RU'));
        $this->assertSame(OwnerLocale::Uk, OwnerLocale::fromMixed(['uk']));
    }
}
