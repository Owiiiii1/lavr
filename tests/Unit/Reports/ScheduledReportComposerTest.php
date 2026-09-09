<?php

namespace Tests\Unit\Reports;

use App\Enums\ScheduledReportType;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Reports\ScheduledReportComposer;
use Tests\TestCase;

class ScheduledReportComposerTest extends TestCase
{
    public function test_truncated_ai_phrasing_falls_back_to_the_deterministic_report(): void
    {
        $composer = new ScheduledReportComposer($this->synthesizer('Доброе утро. Сводка на сегодня,'));

        $result = $composer->compose($this->user(), $this->report(), $this->collected());

        $this->assertFalse($result['ai_used']);
        $this->assertStringContainsString('Заполнить материалы', $result['text']);
        $this->assertStringContainsString('Сделать иконки', $result['text']);
        $this->assertStringContainsString('Календарь сейчас недоступен', $result['text']);
        $this->assertStringNotContainsString('Доброе утро. Сводка на сегодня,', $result['text']);
    }

    public function test_empty_ai_phrasing_falls_back_to_the_deterministic_report(): void
    {
        $composer = new ScheduledReportComposer($this->synthesizer('   '));

        $result = $composer->compose($this->user(), $this->report(), $this->collected());

        $this->assertFalse($result['ai_used']);
        $this->assertStringContainsString('Заполнить материалы', $result['text']);
    }

    public function test_complete_ai_phrasing_replaces_the_deterministic_report(): void
    {
        $composer = new ScheduledReportComposer($this->synthesizer(
            'Доброе утро. Сегодня: заполнить материалы для WOW Cleaning и сделать иконки. Календарь сейчас недоступен.',
        ));

        $result = $composer->compose($this->user(), $this->report(), $this->collected());

        $this->assertTrue($result['ai_used']);
        $this->assertSame(
            'Доброе утро. Сегодня: заполнить материалы для WOW Cleaning и сделать иконки. Календарь сейчас недоступен.',
            $result['text'],
        );
    }

    public function test_mail_digest_fallback_keeps_the_facts_without_quoting_email_bodies(): void
    {
        $composer = new ScheduledReportComposer;

        $text = $composer->compose($this->user(), $this->digestReport(), $this->mailCollected())['text'];

        $this->assertStringContainsString('За период 4 новых письма.', $text);
        $this->assertStringContainsString('Важное: Accademia — Родительское собрание.', $text);
        $this->assertStringContainsString('Ещё: GitHub — Review requested.', $text);
        $this->assertStringContainsString('Рекламных и служебных: 2, без действия.', $text);
        $this->assertStringContainsString('В группах: «WOW Cleaning» — 3, последнее: иконки готовы?', $text);
        $this->assertStringNotContainsString('просят подтвердить присутствие', $text);
        $this->assertStringNotContainsString('requested your review on JARVIS', $text);
        $this->assertStringNotContainsString('• ', $text);
        $this->assertStringNotContainsString('noreply@lastpass.com', $text);
    }

    public function test_mail_digest_fallback_shortens_long_subjects_and_drops_invisible_padding(): void
    {
        $collected = $this->mailCollected();
        $collected['items']['gmail'] = [[
            'sender' => "Registro\u{200C} online <liceolinguistico@marcellinequadronno.it>",
            'subject' => "Registro online per DENYSIUK HLIB - Avviso -\u{200C} Materie del primo giorno di scuola.",
            'bucket' => 'normal',
            'snippet' => "Carissimi, in vista della ripresa delle lezioni \u{200C} \u{200C}",
        ]];

        $text = (new ScheduledReportComposer)->compose($this->user(), $this->digestReport(), $collected)['text'];

        $this->assertStringNotContainsString("\u{200C}", $text);
        $this->assertStringContainsString('Пришло: Registro online — Registro online per DENYSIUK HLIB - Avviso - Materie del primo giorno…', $text);
        $this->assertStringNotContainsString('Carissimi', $text);
    }

    public function test_mail_digest_accepts_a_short_spoken_ai_summary(): void
    {
        $spoken = 'Доброе утро. Одно важное письмо от школы про собрание — нужно подтвердить. Остальное служебное, можно не открывать. В группах тихо.';
        $composer = new ScheduledReportComposer($this->synthesizer($spoken));

        $result = $composer->compose($this->user(), $this->digestReport(), $this->mailCollected());

        $this->assertTrue($result['ai_used']);
        $this->assertSame($spoken, $result['text']);
    }

    public function test_truncated_mail_digest_ai_falls_back_to_prose(): void
    {
        $composer = new ScheduledReportComposer($this->synthesizer('Доброе утро. Сводка по почте,'));

        $result = $composer->compose($this->user(), $this->digestReport(), $this->mailCollected());

        $this->assertFalse($result['ai_used']);
        $this->assertStringContainsString('За период 4 новых письма.', $result['text']);
        $this->assertStringContainsString('Accademia', $result['text']);
        $this->assertStringNotContainsString('Доброе утро. Сводка по почте,', $result['text']);
    }

    private function synthesizer(string $text): SynthesizesProductivityBrief
    {
        return new class($text) implements SynthesizesProductivityBrief
        {
            public function __construct(private readonly string $text) {}

            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return $this->text;
            }
        };
    }

    private function user(): User
    {
        return new User;
    }

    private function report(): ScheduledReport
    {
        $report = new ScheduledReport;
        $report->forceFill([
            'name' => 'Утренний отчёт: планы на сегодня',
            'report_type' => ScheduledReportType::DailyPlan,
        ]);

        return $report;
    }

    private function digestReport(): ScheduledReport
    {
        $report = new ScheduledReport;
        $report->forceFill([
            'name' => 'Утренний дайджест: почта и группы',
            'report_type' => ScheduledReportType::MailGroupsDigest,
        ]);

        return $report;
    }

    /**
     * @return array{items: array<string, mixed>, errors: list<string>, local_date: string, timezone: string, period_mode: string, report_type: string}
     */
    private function mailCollected(): array
    {
        return [
            'items' => [
                'gmail' => [
                    [
                        'sender' => 'Accademia <info@accademiaucraina.it>',
                        'subject' => 'Родительское собрание',
                        'bucket' => 'important',
                        'snippet' => 'просят подтвердить присутствие',
                    ],
                    [
                        'sender' => 'GitHub <notifications@github.com>',
                        'subject' => 'Review requested',
                        'bucket' => 'normal',
                        'snippet' => 'requested your review on JARVIS',
                    ],
                    [
                        'sender' => 'LastPass <noreply@lastpass.com>',
                        'subject' => 'Security notification',
                        'bucket' => 'noise',
                        'snippet' => 'A new device signed in',
                    ],
                    [
                        'sender' => 'Promo <newsletter@shop.test>',
                        'subject' => 'Sale',
                        'bucket' => 'noise',
                        'snippet' => 'unsubscribe here',
                    ],
                ],
                'telegram_groups' => [
                    [
                        'group' => 'WOW Cleaning',
                        'count' => 3,
                        'sample' => 'иконки готовы?',
                    ],
                ],
            ],
            'errors' => [],
            'local_date' => '2026-09-09',
            'timezone' => 'Europe/Rome',
            'period_mode' => 'since_previous_report',
            'report_type' => 'mail_groups_digest',
        ];
    }

    /**
     * @return array{items: array<string, mixed>, errors: list<string>, local_date: string, timezone: string, period_mode: string, report_type: string}
     */
    private function collected(): array
    {
        return [
            'items' => [
                'calendar' => [],
                'tasks' => [
                    ['title' => 'Заполнить материалы'],
                    ['title' => 'Сделать иконки'],
                ],
                'reminders' => [],
                'synthesis' => [],
            ],
            'errors' => ['Календарь сейчас недоступен.'],
            'local_date' => '2026-09-09',
            'timezone' => 'Europe/Rome',
            'period_mode' => 'today',
            'report_type' => 'daily_plan',
        ];
    }
}
