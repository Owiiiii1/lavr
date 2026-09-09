<?php

namespace App\Services\Meetings;

final class MeetingIntelligenceMerger
{
    /**
     * @param  list<array<string, mixed>>  $chunks
     * @return array<string, mixed>
     */
    public function merge(array $chunks): array
    {
        $merged = [
            'summary' => [
                'executive' => '',
                'outcomes' => [],
                'attention' => [],
            ],
            'participants' => [],
            'topics' => [],
            'decisions' => [],
            'action_items' => [],
            'commitments_detected' => [],
            'deadlines' => [],
            'open_questions' => [],
            'risks' => [],
            'follow_ups' => [],
            'unresolved_identities' => [],
            'likely_project' => null,
            'source_references' => [],
        ];

        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $summary = is_array($chunk['summary'] ?? null) ? $chunk['summary'] : [];
            $merged['summary']['outcomes'] = $this->uniqueStrings(array_merge(
                $merged['summary']['outcomes'],
                is_array($summary['outcomes'] ?? null) ? $summary['outcomes'] : [],
            ), 7);
            $merged['summary']['attention'] = $this->uniqueStrings(array_merge(
                $merged['summary']['attention'],
                is_array($summary['attention'] ?? null) ? $summary['attention'] : [],
            ), 8);

            if ($merged['summary']['executive'] === '' && is_string($summary['executive'] ?? null) && trim((string) $summary['executive']) !== '') {
                $merged['summary']['executive'] = trim((string) $summary['executive']);
            }

            foreach (['participants', 'topics', 'unresolved_identities', 'source_references'] as $listKey) {
                $merged[$listKey] = $this->uniqueStrings(array_merge(
                    $merged[$listKey],
                    is_array($chunk[$listKey] ?? null) ? $chunk[$listKey] : [],
                ));
            }

            foreach (['decisions', 'action_items', 'commitments_detected', 'deadlines', 'open_questions', 'risks', 'follow_ups'] as $itemKey) {
                $merged[$itemKey] = $this->mergeItems(
                    $merged[$itemKey],
                    is_array($chunk[$itemKey] ?? null) ? $chunk[$itemKey] : [],
                    $itemKey,
                );
            }

            if ($merged['likely_project'] === null && is_string($chunk['likely_project'] ?? null)) {
                $merged['likely_project'] = trim((string) $chunk['likely_project']) ?: null;
            }
        }

        if ($merged['summary']['executive'] === '' && $merged['summary']['outcomes'] !== []) {
            $merged['summary']['executive'] = implode(' ', array_slice($merged['summary']['outcomes'], 0, 2));
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $existing
     * @param  list<mixed>  $incoming
     * @return list<array<string, mixed>>
     */
    private function mergeItems(array $existing, array $incoming, string $kind): array
    {
        foreach ($incoming as $row) {
            if (! is_array($row)) {
                continue;
            }

            $fingerprint = $this->fingerprint($row, $kind);
            $matched = false;

            foreach ($existing as $index => $current) {
                if ($this->fingerprint($current, $kind) !== $fingerprint) {
                    continue;
                }

                $existing[$index] = $this->stronger($current, $row);
                $matched = true;

                break;
            }

            if (! $matched) {
                $existing[] = $row;
            }
        }

        return array_values($existing);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fingerprint(array $row, string $kind): string
    {
        $parts = match ($kind) {
            'action_items' => [(string) ($row['task'] ?? ''), (string) ($row['owner'] ?? '')],
            'commitments_detected' => [
                (string) ($row['person_name'] ?? $row['person_ref'] ?? ''),
                (string) ($row['action'] ?? ''),
            ],
            'decisions' => [(string) ($row['text'] ?? '')],
            'deadlines' => [(string) ($row['text'] ?? $row['deadline_raw'] ?? ''), (string) ($row['owner'] ?? '')],
            default => [(string) ($row['text'] ?? $row['task'] ?? $row['action'] ?? '')],
        };

        $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', implode('|', $parts)) ?? ''));

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function stronger(array $current, array $incoming): array
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3];
        $currentRank = $rank[$current['confidence'] ?? 'medium'] ?? 2;
        $incomingRank = $rank[$incoming['confidence'] ?? 'medium'] ?? 2;
        $currentExcerpt = mb_strlen((string) data_get($current, 'evidence.excerpt', ''));
        $incomingExcerpt = mb_strlen((string) data_get($incoming, 'evidence.excerpt', ''));

        if ($incomingRank > $currentRank || ($incomingRank === $currentRank && $incomingExcerpt > $currentExcerpt)) {
            return $incoming;
        }

        return $current;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<string>
     */
    private function uniqueStrings(array $values, ?int $limit = null): array
    {
        $unique = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $key = mb_strtolower(trim($value));

            if (! isset($unique[$key])) {
                $unique[$key] = trim($value);
            }
        }

        $items = array_values($unique);

        return $limit === null ? $items : array_slice($items, 0, $limit);
    }
}
