<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\OwnerLocale;

final class ExecutiveBriefTelegramRenderer
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $sections
     * @param  array<string, mixed>  $snapshot
     */
    public function render(string $summary, array $sections, array $snapshot, OwnerLocale $locale, int $maxChars = 3500): string
    {
        $heading = match ($locale) {
            OwnerLocale::En => '☀️ Morning brief',
            OwnerLocale::Ru => '☀️ Утренний бриф',
            default => '☀️ Ранковий бриф',
        };

        $open = match ($locale) {
            OwnerLocale::En => 'Open in LAVR',
            OwnerLocale::Ru => 'Открыть в LAVR',
            default => 'Відкрити в LAVR',
        };

        $attention = is_array($sections['attention'] ?? null) ? $sections['attention'] : [];
        $lines = [$heading, '', $summary];

        if ($attention !== []) {
            $lines[] = '';
            $lines[] = match ($locale) {
                OwnerLocale::En => '🔴 '.$this->countLabel(count($attention), $locale),
                OwnerLocale::Ru => '🔴 '.$this->countLabel(count($attention), $locale),
                default => '🔴 '.$this->countLabel(count($attention), $locale),
            };
            foreach (array_slice($attention, 0, 5) as $row) {
                $lines[] = '• '.$this->line($row);
            }
        }

        foreach (['today' => '📅', 'overdue' => '⏳', 'commitments' => '✅', 'meetings' => '🗓', 'inbox' => '✉️'] as $section => $icon) {
            $rows = is_array($sections[$section] ?? null) ? $sections[$section] : [];
            if ($rows === []) {
                continue;
            }
            $lines[] = '';
            $lines[] = $icon.' '.$this->sectionLabel($section, $locale);
            foreach (array_slice($rows, 0, 4) as $row) {
                $lines[] = '• '.$this->line($row);
            }
        }

        foreach ($snapshot['errors'] ?? [] as $error) {
            if (is_string($error) && trim($error) !== '') {
                $lines[] = '';
                $lines[] = trim($error);
            }
        }

        $lines[] = '';
        $lines[] = $open;

        $text = trim(implode("\n", $lines));
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $short = [$heading, '', $summary, ''];
        foreach (array_slice($attention, 0, 5) as $row) {
            $short[] = '• '.$this->line($row);
        }
        $short[] = '';
        $short[] = $open;

        $text = trim(implode("\n", $short));
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = mb_substr($text, 0, max(32, $maxChars - mb_strlen($open) - 8));

        return trim($cut)."\n\n".$open;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function line(array $row): string
    {
        $text = trim((string) ($row['summary'] ?? $row['title'] ?? ''));

        return mb_substr($text, 0, 220);
    }

    private function countLabel(int $count, OwnerLocale $locale): string
    {
        return match ($locale) {
            OwnerLocale::En => $count.' need attention',
            OwnerLocale::Ru => $count.' требуют внимания',
            default => $count.' потребують уваги',
        };
    }

    private function sectionLabel(string $section, OwnerLocale $locale): string
    {
        return match ([$locale, $section]) {
            [OwnerLocale::En, 'today'] => 'Today',
            [OwnerLocale::En, 'overdue'] => 'Overdue',
            [OwnerLocale::En, 'commitments'] => 'Commitments',
            [OwnerLocale::En, 'meetings'] => 'Meetings',
            [OwnerLocale::En, 'inbox'] => 'Inbox',
            [OwnerLocale::Ru, 'today'] => 'Сегодня',
            [OwnerLocale::Ru, 'overdue'] => 'Просрочено',
            [OwnerLocale::Ru, 'commitments'] => 'Обязательства',
            [OwnerLocale::Ru, 'meetings'] => 'Встречи',
            [OwnerLocale::Ru, 'inbox'] => 'Почта',
            default => match ($section) {
                'today' => 'Сьогодні',
                'overdue' => 'Прострочено',
                'commitments' => 'Зобов’язання',
                'meetings' => 'Зустрічі',
                'inbox' => 'Пошта',
                default => $section,
            },
        };
    }
}
