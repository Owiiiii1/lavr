<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefType;
use App\Enums\OwnerLocale;
use App\Models\User;
use App\Services\Automation\ReportOutputValidator;
use App\Services\Productivity\SynthesizesProductivityBrief;
use Illuminate\Support\Facades\Log;

final class ExecutiveBriefComposer
{
    public function __construct(
        private readonly ReportOutputValidator $validator = new ReportOutputValidator,
        private readonly ?SynthesizesProductivityBrief $synthesizer = null,
    ) {}

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $snapshot
     * @return array{summary: string, text: string, ai_used: bool}
     */
    public function compose(User $user, ExecutiveBriefType $type, array $sections, array $snapshot, OwnerLocale $locale): array
    {
        $deterministic = $this->deterministic($sections, $snapshot, $locale);
        $summary = $this->summary($sections, $snapshot, $locale);
        $text = $deterministic;
        $aiUsed = false;

        if ($this->synthesizer !== null) {
            $phrased = $this->synthesizer->synthesize($user, 'executive_'.$type->value, $deterministic, [
                'items' => $sections,
                'errors' => $snapshot['errors'] ?? [],
            ]);
            $candidate = is_string($phrased) ? trim($phrased) : '';
            $reason = $candidate === '' ? 'empty' : $this->rejectAi($candidate, $sections);
            if ($reason === null) {
                $text = $candidate;
                $aiUsed = true;
            } else {
                Log::info('executive brief phrasing skipped', ['reason' => $reason]);
            }
        }

        return [
            'summary' => $summary,
            'text' => $text,
            'ai_used' => $aiUsed,
        ];
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $snapshot
     */
    public function deterministic(array $sections, array $snapshot, OwnerLocale $locale): string
    {
        $labels = $this->labels($locale);
        $lines = [];
        foreach (['attention', 'today', 'overdue', 'commitments', 'meetings', 'decisions', 'people', 'projects', 'inbox', 'risks', 'followups'] as $section) {
            $rows = is_array($sections[$section] ?? null) ? $sections[$section] : [];
            if ($rows === []) {
                continue;
            }
            $lines[] = $labels[$section] ?? $section;
            foreach (array_slice($rows, 0, 6) as $row) {
                $title = trim((string) ($row['summary'] ?? $row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $prefix = ($row['body_read'] ?? true) === false ? '· ' : '• ';
                $lines[] = $prefix.$title;
            }
        }

        foreach ($snapshot['errors'] ?? [] as $error) {
            if (is_string($error) && trim($error) !== '') {
                $lines[] = trim($error);
            }
        }

        $text = trim(implode("\n", $lines));

        return $text !== '' ? $text : $this->emptyCopy($locale, $sections);
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $snapshot
     */
    public function summary(array $sections, array $snapshot, OwnerLocale $locale): string
    {
        $attention = count($sections['attention'] ?? []);
        $meetings = count($sections['meetings'] ?? []) + count($sections['today'] ?? []);
        $overdue = count($sections['overdue'] ?? []);

        if ($attention === 0 && $overdue === 0) {
            return $this->emptyCopy($locale, $sections, $meetings);
        }

        return match ($locale) {
            OwnerLocale::En => $attention.' need attention. Overdue: '.$overdue.'.',
            OwnerLocale::Ru => $attention.' требуют внимания. Просрочено: '.$overdue.'.',
            default => $attention.' потребують уваги. Прострочено: '.$overdue.'.',
        };
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    public function emptyCopy(OwnerLocale $locale, array $sections, ?int $meetings = null): string
    {
        $meetings ??= count($sections['meetings'] ?? []) + count($sections['today'] ?? []);

        return match ($locale) {
            OwnerLocale::En => 'No critical issues today. '.$meetings.' meetings on the calendar, no overdue commitments.',
            OwnerLocale::Ru => 'На сегодня нет критических вопросов. '.$meetings.' встреч в календаре, просроченных обязательств нет.',
            default => 'На сьогодні немає критичних питань. '.$meetings.' зустрічі в календарі, прострочених зобов’язань немає.',
        };
    }

    /**
     * @return array<string, string>
     */
    private function labels(OwnerLocale $locale): array
    {
        return match ($locale) {
            OwnerLocale::En => [
                'attention' => 'Attention now',
                'today' => 'Today',
                'overdue' => 'Overdue',
                'commitments' => 'Commitments',
                'meetings' => 'Meetings',
                'decisions' => 'Decisions needed',
                'people' => 'People',
                'projects' => 'Projects',
                'inbox' => 'Inbox',
                'risks' => 'Risks',
                'followups' => 'Follow-ups',
            ],
            OwnerLocale::Ru => [
                'attention' => 'Сейчас важно',
                'today' => 'Сегодня',
                'overdue' => 'Просрочено',
                'commitments' => 'Обязательства',
                'meetings' => 'Встречи',
                'decisions' => 'Нужны решения',
                'people' => 'Люди',
                'projects' => 'Проекты',
                'inbox' => 'Почта',
                'risks' => 'Риски',
                'followups' => 'Follow-up',
            ],
            default => [
                'attention' => 'Зараз важливо',
                'today' => 'Сьогодні',
                'overdue' => 'Прострочено',
                'commitments' => 'Зобов’язання',
                'meetings' => 'Зустрічі',
                'decisions' => 'Потрібні рішення',
                'people' => 'Люди',
                'projects' => 'Проєкти',
                'inbox' => 'Пошта',
                'risks' => 'Ризики',
                'followups' => 'Follow-up',
            ],
        };
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     */
    public function rejectAi(string $candidate, array $sections): ?string
    {
        $reason = $this->validator->rejectReason($candidate);
        if ($reason !== null) {
            return $reason;
        }

        $known = $this->knownSourceIds($sections);
        if (preg_match_all('/source_id["\']?\s*[:=]\s*(\d+)/u', $candidate, $matches) > 0) {
            foreach ($matches[1] as $id) {
                if (! in_array((int) $id, $known, true)) {
                    return 'unknown_source';
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int>  $knownSourceIds
     */
    public function rejectInventedItems(array $items, array $knownSourceIds): ?string
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['source_id'] ?? null;
            if (is_numeric($id) && (int) $id > 0 && ! in_array((int) $id, $knownSourceIds, true)) {
                return 'unknown_source';
            }
        }

        return null;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @return list<int>
     */
    private function knownSourceIds(array $sections): array
    {
        $ids = [];
        foreach ($sections as $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if (is_array($row) && is_numeric($row['source_id'] ?? null) && (int) $row['source_id'] > 0) {
                    $ids[] = (int) $row['source_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
