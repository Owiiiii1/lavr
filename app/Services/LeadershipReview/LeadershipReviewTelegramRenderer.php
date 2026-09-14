<?php

namespace App\Services\LeadershipReview;

use App\Enums\OwnerLocale;

final class LeadershipReviewTelegramRenderer
{
    /**
     * @param  array<string, mixed>  $pack
     */
    public function render(string $summary, array $pack, string $href, OwnerLocale $locale, int $maxChars = 1200): string
    {
        $attention = is_array($pack['attention'] ?? null) ? $pack['attention'] : [];
        $strengths = is_array($pack['strengths'] ?? null) ? $pack['strengths'] : [];
        $attentionCount = count($attention);

        $lines = [
            match ($locale) {
                OwnerLocale::En => '📊 Leadership Review',
                OwnerLocale::Ru => '📊 Leadership Review',
                default => '📊 Leadership Review',
            },
            '',
            match ($locale) {
                OwnerLocale::En => '⚠️ '.$attentionCount.' attention areas',
                OwnerLocale::Ru => '⚠️ '.$attentionCount.' зон внимания',
                default => '⚠️ '.$attentionCount.' зони уваги',
            },
        ];

        foreach (array_slice($attention, 0, 3) as $row) {
            $lines[] = '• '.trim((string) ($row['title'] ?? $row['observation'] ?? ''));
        }

        $lines[] = '';
        $lines[] = match ($locale) {
            OwnerLocale::En => '✅ Strengths',
            OwnerLocale::Ru => '✅ Сильные стороны',
            default => '✅ Сильні сторони',
        };
        $shown = 0;
        foreach (array_slice($strengths, 0, 3) as $row) {
            $text = trim((string) ($row['observation'] ?? $row['title'] ?? ''));
            if ($text === '') {
                continue;
            }
            $lines[] = '• '.$text;
            $shown++;
        }
        if ($shown === 0) {
            $lines[] = '• —';
        }

        $lines[] = '';
        $lines[] = '[Open in LAVR] '.$href;

        $text = trim(implode("\n", $lines));
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        return mb_substr($text, 0, $maxChars - 1).'…';
    }
}
