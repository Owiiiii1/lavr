<?php

namespace App\Services\Reports;

use App\Enums\ScheduledReportType;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Automation\ReportOutputValidator;
use App\Services\Productivity\ProductivityBriefPhrasing;
use App\Services\Productivity\SynthesizesProductivityBrief;
use Illuminate\Support\Facades\Log;

final class ScheduledReportComposer
{
    public function __construct(
        private readonly ?SynthesizesProductivityBrief $synthesizer = null,
        private readonly ReportOutputValidator $validator = new ReportOutputValidator,
    ) {}

    /**
     * @param  array{items: array<string, mixed>, errors: list<string>, local_date: string, timezone: string, period_mode: string, report_type: string}  $collected
     * @return array{text: string, ai_used: bool}
     */
    public function compose(User $user, ScheduledReport $report, array $collected): array
    {
        $deterministic = $this->deterministic($report, $collected);
        $text = $deterministic;
        $aiUsed = false;

        if ($this->synthesizer !== null) {
            $phrased = $this->synthesizer->synthesize($user, $report->report_type->value, $deterministic, [
                'report' => $report->name,
                'period_mode' => $collected['period_mode'] ?? null,
                'items' => $collected['items'] ?? [],
                'errors' => $collected['errors'] ?? [],
            ]);

            $candidate = is_string($phrased) ? trim($phrased) : '';
            $skip = $this->phrasingSkipReason($deterministic, $candidate, $report->report_type)
                ?? $this->validator->rejectReason($candidate);
            if ($skip === null) {
                $text = $candidate;
                $aiUsed = true;
            } else {
                Log::info('scheduled_report.phrasing_skipped', [
                    'reason' => $skip,
                    'report_type' => $report->report_type->value,
                ]);
            }
        }

        return [
            'text' => $text,
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * @param  array{items: array<string, mixed>, errors: list<string>, local_date?: string, timezone?: string}  $collected
     */
    public function deterministic(ScheduledReport $report, array $collected): string
    {
        $items = is_array($collected['items'] ?? null) ? $collected['items'] : [];
        $heading = match ($report->report_type) {
            ScheduledReportType::DailyPlan => 'Планы на сегодня',
            ScheduledReportType::TomorrowPlan => 'Планы на завтра',
            ScheduledReportType::MailGroupsDigest => 'Почта и группы',
            ScheduledReportType::CustomComposite => $report->name,
        };

        $lines = [
            $heading.' · '.($collected['local_date'] ?? '').' ('.($collected['timezone'] ?? '').')',
        ];

        if ($report->report_type === ScheduledReportType::MailGroupsDigest) {
            $lines[] = $this->mailSection($items['gmail'] ?? []);
            $lines[] = $this->groupsSection($items['telegram_groups'] ?? []);
            $lines[] = $this->namedSection('Follow-ups', $items['commitments'] ?? []);
        } else {
            $lines[] = $this->namedSection('Important', $this->importantItems($items));
            $lines[] = $this->namedSection('Календарь', $items['calendar'] ?? []);
            $lines[] = $this->namedSection('Задачи', $items['tasks'] ?? []);
            $lines[] = $this->namedSection('Напоминания', $items['reminders'] ?? []);
            $lines[] = $this->namedSection('Обязательства', $items['commitments'] ?? []);
            $lines[] = $this->namedSection('Планы и обязательства', $items['synthesis'] ?? []);
        }

        foreach ($collected['errors'] ?? [] as $error) {
            if (is_string($error) && trim($error) !== '') {
                $lines[] = trim($error).' Отчёт составлен по доступным источникам.';
            }
        }

        $text = trim(implode("\n", array_filter($lines)));

        return $text !== '' ? $text : 'На цей момент немає нових важливих подій.';
    }

    /**
     * @param  list<array{title?: string}>  $items
     */
    private function namedSection(string $title, array $items): string
    {
        if ($items === []) {
            return '';
        }

        $names = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $name = trim((string) ($item['title'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if ($names === []) {
            return '';
        }

        return $title.': '.implode('; ', array_slice($names, 0, 8));
    }

    /**
     * @param  array<string, mixed>  $items
     * @return list<array{title?: string}>
     */
    private function importantItems(array $items): array
    {
        $important = [];
        foreach ($items['gmail'] ?? [] as $item) {
            if (! is_array($item) || ($item['bucket'] ?? '') !== 'important') {
                continue;
            }
            $title = trim((string) ($item['sender'] ?? '')).' — '.trim((string) ($item['subject'] ?? ''));
            if ($title !== ' — ') {
                $important[] = ['title' => $title];
            }
        }

        return $important;
    }

    /**
     * @param  list<array{title?: string}>  $items
     */
    private function section(string $title, array $items): string
    {
        return $this->namedSection($title, $items);
    }

    /**
     * @param  list<array{sender?: string, subject?: string, bucket?: string, snippet?: string}>  $items
     */
    private function mailSection(array $items): string
    {
        if ($items === []) {
            return 'Новых писем нет.';
        }

        $important = [];
        $normal = [];
        $noise = 0;

        foreach ($items as $item) {
            $bucket = (string) ($item['bucket'] ?? 'normal');
            if ($bucket === 'noise') {
                $noise++;

                continue;
            }

            $fact = $this->mailFact($item);
            if ($bucket === 'important') {
                $important[] = $fact;
            } else {
                $normal[] = $fact;
            }
        }

        $lines = [$this->mailCountLine(count($items))];
        if ($important !== []) {
            $lines[] = 'Важное: '.$this->joinFacts(array_slice($important, 0, 4)).'.';
        }
        if ($normal !== []) {
            $prefix = $important === [] ? 'Пришло: ' : 'Ещё: ';
            $lines[] = $prefix.$this->joinFacts(array_slice($normal, 0, 5)).'.';
        }
        if ($noise > 0) {
            $lines[] = $noise === 1
                ? 'Одно рекламное или служебное, без действия.'
                : 'Рекламных и служебных: '.$noise.', без действия.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{group?: string, count?: int, sample?: string}>  $items
     */
    private function groupsSection(array $items): string
    {
        if ($items === []) {
            return 'В группах новой активности нет.';
        }

        $bits = [];
        foreach (array_slice($items, 0, 6) as $item) {
            $name = (string) ($item['group'] ?? 'Группа');
            $count = (int) ($item['count'] ?? 0);
            $sample = $this->clip(trim((string) ($item['sample'] ?? '')), 80);
            $bit = '«'.$name.'» — '.$count;
            if ($sample !== '') {
                $bit .= ', последнее: '.$sample;
            }
            $bits[] = $bit;
        }

        $summary = 'В группах: '.implode('; ', $bits);
        if (preg_match('/[.!?…]$/u', $summary) !== 1) {
            $summary .= '.';
        }

        return $summary;
    }

    /**
     * Fallback wording only. Snippets stay out of it: raw email bodies arrive in
     * the sender's language and would land in the report untranslated.
     *
     * @param  array{sender?: string, subject?: string}  $item
     */
    private function mailFact(array $item): string
    {
        $subject = $this->clip(trim((string) ($item['subject'] ?? '')), 70);

        return $this->senderLabel((string) ($item['sender'] ?? ''))
            .' — '.($subject !== '' ? $subject : 'без темы');
    }

    /**
     * @param  list<string>  $facts
     */
    private function joinFacts(array $facts): string
    {
        return implode('; ', $facts);
    }

    private function mailCountLine(int $count): string
    {
        if ($count === 1) {
            return 'За период одно новое письмо.';
        }

        $mod100 = $count % 100;
        $mod10 = $count % 10;
        $word = 'писем';
        if ($mod100 < 11 || $mod100 > 14) {
            if ($mod10 === 1) {
                $word = 'письмо';
            } elseif ($mod10 >= 2 && $mod10 <= 4) {
                $word = 'письма';
            }
        }

        return 'За период '.$count.' новых '.$word.'.';
    }

    private function senderLabel(string $sender): string
    {
        $sender = $this->clip($sender, 60);
        if ($sender === '') {
            return 'Неизвестный отправитель';
        }

        if (preg_match('/^"?([^"<]+)"?\s*</u', $sender, $matches) === 1) {
            $name = trim($matches[1]);
            if ($name !== '') {
                return $name;
            }
        }

        return $sender;
    }

    private function clip(string $text, int $max): string
    {
        $text = MailTextNormalizer::normalize($text);
        if ($text === '' || mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max), " \t.,;:").'…';
    }

    private function phrasingSkipReason(string $deterministic, string $candidate, ScheduledReportType $type): ?string
    {
        if ($candidate === '') {
            return 'empty';
        }

        if (! ProductivityBriefPhrasing::isComplete($candidate)) {
            return 'incomplete';
        }

        if ($type !== ScheduledReportType::MailGroupsDigest
            && ! ProductivityBriefPhrasing::isSubstantial($deterministic, $candidate)) {
            return 'too_short';
        }

        return null;
    }
}
