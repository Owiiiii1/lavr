<?php

namespace Tests\Unit\Support;

use App\Enums\OwnerLocale;
use App\Support\OwnerCopy;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class OwnerCopyTest extends TestCase
{
    public function test_today_copy_uses_the_requested_locale(): void
    {
        $this->assertSame('Nothing urgent.', OwnerCopy::get('today.no_urgent', OwnerLocale::En));
        $this->assertSame('Немає термінових пунктів.', OwnerCopy::get('today.no_urgent', OwnerLocale::Uk));
        $this->assertSame('Нет срочных пунктов.', OwnerCopy::get('today.no_urgent', OwnerLocale::Ru));
    }

    public function test_missing_ukrainian_key_returns_the_key_and_logs(): void
    {
        Log::spy();

        $this->assertSame(
            'today.missing_owner_copy_key',
            OwnerCopy::get('today.missing_owner_copy_key', OwnerLocale::En),
        );

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context): bool {
            return $message === 'owner_locale_missing_key'
                && ($context['key'] ?? null) === 'today.missing_owner_copy_key';
        })->once();
    }
}
