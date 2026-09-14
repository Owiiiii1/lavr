<?php

namespace Tests\Unit;

use App\Enums\OwnerLocale;
use App\Services\LeadershipReview\LeadershipReviewWordingGuard;
use PHPUnit\Framework\TestCase;

class LeadershipReviewWordingGuardTest extends TestCase
{
    public function test_rejects_personality_language_in_uk_en_ru(): void
    {
        $guard = new LeadershipReviewWordingGuard;

        $this->assertSame('personality', $guard->rejectReason('Сергей ленивый'));
        $this->assertSame('personality', $guard->rejectReason('Марина токсичная'));
        $this->assertSame('personality', $guard->rejectReason('weak employee'));
        $this->assertSame('personality', $guard->rejectReason('narcissistic leader'));
        $this->assertSame('personality', $guard->rejectReason('слабкий співробітник'));
        $this->assertNull($guard->rejectReason('У 4 зобов’язань немає дедлайну.'));
        $this->assertSame('raw_json', $guard->rejectReason('{"findings":[]}'));
    }

    public function test_locale_mismatch_for_long_english_on_ukrainian(): void
    {
        $guard = new LeadershipReviewWordingGuard;
        $english = str_repeat('Deadline coverage improved across meetings and commitments. ', 4);

        $this->assertTrue($guard->localeMismatch($english, OwnerLocale::Uk));
        $this->assertFalse($guard->localeMismatch('У 4 зобов’язань немає дедлайну.', OwnerLocale::Uk));
    }
}
