<?php

namespace Tests\Unit\Automation;

use App\Services\Automation\ReportOutputValidator;
use PHPUnit\Framework\TestCase;

class ReportOutputValidatorTest extends TestCase
{
    public function test_rejects_raw_json_technical_dump_and_empty(): void
    {
        $validator = new ReportOutputValidator;

        $this->assertSame('empty', $validator->rejectReason(''));
        $this->assertSame('raw_json', $validator->rejectReason('{"subjects":["a","b"]}'));
        $this->assertSame('technical_markers', $validator->rejectReason('SQLSTATE[HY000] Exception: boom'));
        $this->assertSame('ask_to_query', $validator->rejectReason('Ask me to query Gmail manually'));
        $this->assertSame('truncated', $validator->rejectReason('Доброе утро. Сводка на сегодня,'));
        $this->assertNull($validator->rejectReason('Доброе утро. Сегодня два дела и одно письмо от школы.'));
    }

    public function test_rejects_giant_subject_dump(): void
    {
        $lines = [];
        for ($i = 0; $i < 10; $i++) {
            $lines[] = '- Sender '.$i.' — Very long subject line number '.$i.' with extra padding text';
        }

        $this->assertSame('subject_dump', (new ReportOutputValidator)->rejectReason(implode("\n", $lines).str_repeat(' x', 200)));
    }
}
