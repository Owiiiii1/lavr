<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Services\Meetings\Exceptions\MeetingIntelligenceException;
use Carbon\CarbonImmutable;

final class MeetingIntelligenceValidator
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, Meeting $meeting): array
    {
        $required = [
            'summary',
            'participants',
            'topics',
            'decisions',
            'action_items',
            'commitments_detected',
            'deadlines',
            'open_questions',
            'risks',
            'follow_ups',
            'unresolved_identities',
        ];

        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                throw new MeetingIntelligenceException('invalid_output', 'Missing key: '.$key);
            }
        }

        $summary = $this->summary($payload['summary']);
        $anchor = $meeting->started_at?->toImmutable();

        return [
            'summary' => $summary,
            'participants' => $this->stringList($payload['participants']),
            'topics' => $this->stringList($payload['topics']),
            'decisions' => $this->items($payload['decisions'], ['text'], $anchor),
            'action_items' => $this->items($payload['action_items'], ['task'], $anchor, ['status' => 'detected']),
            'commitments_detected' => $this->items($payload['commitments_detected'], ['action'], $anchor),
            'deadlines' => $this->items($payload['deadlines'], ['text'], $anchor),
            'open_questions' => $this->items($payload['open_questions'], ['text'], $anchor, withEvidence: false),
            'risks' => $this->items($payload['risks'], ['text'], $anchor, withEvidence: false),
            'follow_ups' => $this->items($payload['follow_ups'], ['text'], $anchor, withEvidence: false),
            'unresolved_identities' => $this->stringList($payload['unresolved_identities']),
            'likely_project' => $this->nullableString($payload['likely_project'] ?? null),
            'source_references' => is_array($payload['source_references'] ?? null)
                ? array_values(array_filter($payload['source_references'], 'is_string'))
                : [],
        ];
    }

    /**
     * @return array{executive: string, outcomes: list<string>, attention: list<string>}
     */
    private function summary(mixed $value): array
    {
        if (! is_array($value)) {
            throw new MeetingIntelligenceException('invalid_output', 'summary must be an object');
        }

        $outcomes = $this->stringList($value['outcomes'] ?? []);
        $outcomes = array_slice($outcomes, 0, 7);

        if ($outcomes === []) {
            throw new MeetingIntelligenceException('invalid_output', 'summary.outcomes required');
        }

        return [
            'executive' => $this->nullableString($value['executive'] ?? null) ?? implode(' ', array_slice($outcomes, 0, 2)),
            'outcomes' => $outcomes,
            'attention' => $this->stringList($value['attention'] ?? []),
        ];
    }

    /**
     * @param  list<string>  $required
     * @param  array<string, mixed>  $defaults
     * @return list<array<string, mixed>>
     */
    private function items(mixed $value, array $required, ?CarbonImmutable $anchor, array $defaults = [], bool $withEvidence = true): array
    {
        if (! is_array($value)) {
            throw new MeetingIntelligenceException('invalid_output', 'list required');
        }

        $items = [];

        foreach ($value as $row) {
            if (! is_array($row) || array_is_list($row)) {
                continue;
            }

            $item = $defaults;

            foreach ($row as $key => $field) {
                if (! is_string($key)) {
                    continue;
                }

                if ($key === 'evidence') {
                    $item['evidence'] = $this->evidence($field);

                    continue;
                }

                if ($key === 'confidence') {
                    $item['confidence'] = $this->confidence($field);

                    continue;
                }

                if ($key === 'deadline_at' || $key === 'deadline') {
                    $item['deadline_raw'] = is_string($row['deadline_raw'] ?? $row['deadline'] ?? null)
                        ? trim((string) ($row['deadline_raw'] ?? $row['deadline']))
                        : $this->nullableString($field);
                    $item['deadline_at'] = $this->deadlineAt($row, $anchor);

                    continue;
                }

                if (is_string($field) || $field === null) {
                    $item[$key] = $this->nullableString($field);
                }
            }

            foreach ($required as $key) {
                if (! filled($item[$key] ?? null)) {
                    continue 2;
                }
            }

            if ($withEvidence && ! isset($item['evidence'])) {
                $item['evidence'] = $this->evidence($row['evidence'] ?? null);
            }

            if (! isset($item['confidence'])) {
                $item['confidence'] = $this->confidence($row['confidence'] ?? 'medium');
            }

            if (! array_key_exists('deadline_at', $item) && isset($row['deadline_raw'])) {
                $item['deadline_raw'] = $this->nullableString($row['deadline_raw']);
                $item['deadline_at'] = $this->deadlineAt($row, $anchor);
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array{excerpt: string|null, speaker: string|null, timestamp: string|null, start_offset: int|null, end_offset: int|null}
     */
    private function evidence(mixed $value): array
    {
        if (! is_array($value)) {
            $excerpt = $this->nullableString($value);

            return [
                'excerpt' => $this->clip($excerpt),
                'speaker' => null,
                'timestamp' => null,
                'start_offset' => null,
                'end_offset' => null,
            ];
        }

        $start = isset($value['start_offset']) && is_numeric($value['start_offset']) ? (int) $value['start_offset'] : null;
        $end = isset($value['end_offset']) && is_numeric($value['end_offset']) ? (int) $value['end_offset'] : null;

        return [
            'excerpt' => $this->clip($this->nullableString($value['excerpt'] ?? $value['text'] ?? null)),
            'speaker' => $this->nullableString($value['speaker'] ?? null),
            'timestamp' => $this->nullableString($value['timestamp'] ?? null),
            'start_offset' => $start,
            'end_offset' => $end,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function deadlineAt(array $row, ?CarbonImmutable $anchor): ?string
    {
        $raw = $this->nullableString($row['deadline_at'] ?? null);

        if ($raw === null) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        $relative = $this->nullableString($row['deadline_raw'] ?? $row['deadline'] ?? $row['text'] ?? null);

        if ($anchor === null && $this->looksRelative($relative)) {
            return null;
        }

        return $parsed->toIso8601String();
    }

    private function looksRelative(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $haystack = mb_strtolower($text);

        return (bool) preg_match('/\b(tomorrow|yesterday|today|next|this|завтра|післязавтра|сьогодні|вчора|наступн|цього|пятниц|п\'ятниц|понедельник|вівтор|серед|четвер|субот|неділ|недел|week|тижн)\b/u', $haystack);
    }

    private function confidence(mixed $value): string
    {
        $raw = is_string($value) ? mb_strtolower(trim($value)) : 'medium';

        return in_array($raw, ['high', 'medium', 'low'], true) ? $raw : 'medium';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $items = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $items[] = trim($item);
            } elseif (is_array($item)) {
                $name = $this->nullableString($item['display_name'] ?? $item['name'] ?? $item['text'] ?? $item['topic'] ?? null);

                if ($name !== null) {
                    $items[] = $name;
                }
            }
        }

        return array_values(array_unique($items));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '' || in_array(mb_strtolower($trimmed), ['null', 'unknown', 'n/a', 'none'], true)) {
            return null;
        }

        return $trimmed;
    }

    private function clip(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $limit = MeetingConfig::maxEvidenceChars();

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }
}
