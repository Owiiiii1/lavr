<?php

namespace Tests\Unit\Reports;

use App\Services\Reports\MailTextNormalizer;
use PHPUnit\Framework\TestCase;

class MailTextNormalizerTest extends TestCase
{
    public function test_it_strips_the_invisible_padding_marketing_mail_adds_to_previews(): void
    {
        $padded = "Week 1 checklist for success \u{200C} \u{200C} \u{200C}\u{FEFF} \u{00AD}";

        $this->assertSame('Week 1 checklist for success', MailTextNormalizer::normalize($padded));
    }

    public function test_it_collapses_newlines_and_non_breaking_spaces(): void
    {
        $this->assertSame(
            'Carissimi, in vista della ripresa',
            MailTextNormalizer::normalize("Carissimi,\n\n in\u{00A0}vista  della\tripresa "),
        );
    }

    public function test_snippet_clips_to_the_requested_length_after_normalizing(): void
    {
        $snippet = MailTextNormalizer::snippet("\u{200C}".str_repeat('а', 200));

        $this->assertSame(180, mb_strlen($snippet));
    }
}
