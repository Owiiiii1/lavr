<?php

namespace App\Services\LeadershipReview;

use App\Enums\LeadershipFindingConfidence;
use App\Enums\LeadershipFindingSeverity;
use App\Enums\OwnerLocale;

final class LeadershipReviewWordingGuard
{
    /**
     * @return list<string>
     */
    public static function prohibitedPatterns(): array
    {
        return [
            'lazy',
            'toxic',
            'incompetent',
            'weak person',
            'weak employee',
            'weak leader',
            'depressed',
            'manipulative',
            'narcissistic',
            'unreliable person',
            'personality',
            'psycholog',
            'morale',
            'character flaw',
            'stupid',
            'worthless',
            'underperformer',
            'ленив',
            'лінив',
            'токсичн',
            'некомпетент',
            'слабкий лідер',
            'слабкий співробітник',
            'слабый лидер',
            'слабый сотрудник',
            'депрес',
            'маніпулятив',
            'манипулятив',
            'нарцис',
            'ненадійна людина',
            'ненадежн',
            'психолог',
        ];
    }

    public function rejectReason(string $text): ?string
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return 'empty';
        }
        if (preg_match('/^\s*[\{\[]/u', $trimmed) === 1 || preg_match('/```(?:json)?/i', $trimmed) === 1) {
            return 'raw_json';
        }
        $lower = mb_strtolower($trimmed);
        foreach (self::prohibitedPatterns() as $pattern) {
            if ($pattern !== '' && str_contains($lower, $pattern)) {
                return 'personality';
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $knownNames
     */
    public function mentionsUnknownPerson(string $text, array $knownNames): bool
    {
        if (preg_match_all('/\b([A-ZА-ЯІЇЄҐ][\p{L}\'-]{2,})\b/u', $text, $matches) !== false) {
            $names = $matches[1] ?? [];
        } else {
            $names = [];
        }

        $allow = ['Owner', 'CEO', 'LAVR', 'Chicago', 'Zoom', 'Telegram', 'Gmail'];
        $normalizedKnown = array_map(static fn (string $name): string => mb_strtolower(trim($name)), $knownNames);

        foreach ($names as $name) {
            if (in_array($name, $allow, true)) {
                continue;
            }
            $needle = mb_strtolower($name);
            $matched = false;
            foreach ($normalizedKnown as $known) {
                if ($known === '') {
                    continue;
                }
                if ($known === $needle || str_contains($known, $needle) || str_contains($needle, explode(' ', $known)[0] ?? '')) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched && mb_strlen($name) > 3) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    public function findingHasEvidence(array $finding): bool
    {
        $refs = is_array($finding['evidence_refs'] ?? null) ? $finding['evidence_refs'] : [];
        foreach ($refs as $ref) {
            if (is_array($ref) && (int) ($ref['id'] ?? 0) > 0 && in_array((string) ($ref['type'] ?? ''), ['commitment', 'meeting', 'project', 'person', 'automation_run'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $finding
     */
    public function isMajor(array $finding): bool
    {
        $severity = (string) ($finding['severity'] ?? '');

        return in_array($severity, [
            LeadershipFindingSeverity::High->value,
            LeadershipFindingSeverity::Critical->value,
        ], true);
    }

    public function sampleConfidence(int $sample): LeadershipFindingConfidence
    {
        if ($sample < LeadershipReviewMetrics::MIN_SAMPLE) {
            return LeadershipFindingConfidence::Low;
        }
        if ($sample < 8) {
            return LeadershipFindingConfidence::Medium;
        }

        return LeadershipFindingConfidence::High;
    }

    public function localeMismatch(string $text, OwnerLocale $locale): bool
    {
        $trimmed = trim($text);
        if ($trimmed === '' || mb_strlen($trimmed) < 40) {
            return false;
        }

        $cyrillic = preg_match_all('/\p{Cyrillic}/u', $trimmed) ?: 0;
        $latin = preg_match_all('/[A-Za-z]/', $trimmed) ?: 0;

        return match ($locale) {
            OwnerLocale::En => $cyrillic > $latin && $cyrillic > 20,
            default => $latin > $cyrillic && $latin > 40,
        };
    }
}
