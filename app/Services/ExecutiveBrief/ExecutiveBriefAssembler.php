<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefPriority;
use App\Models\ExecutiveBrief;

final class ExecutiveBriefAssembler
{
    /**
     * @param  list<ExecutiveBriefItem>  $items
     * @return array{sections: array<string, list<array<string, mixed>>>, attention_count: int, priority_score: int, items: list<ExecutiveBriefItem>}
     */
    public function assemble(array $items, ?ExecutiveBrief $previous, int $attentionLimit = 7): array
    {
        $items = $this->cluster($items);
        $items = $this->applyDelta($items, $previous);
        usort($items, static fn (ExecutiveBriefItem $left, ExecutiveBriefItem $right): int => $right->score <=> $left->score);

        $attentionKeys = [];
        $attention = [];
        foreach ($items as $item) {
            if (count($attention) >= $attentionLimit) {
                break;
            }
            if (! $this->belongsInAttention($item)) {
                continue;
            }
            $attention[] = $item->withSection('attention');
            $attentionKeys[$item->dedupeKey] = true;
        }

        $sections = [
            'attention' => $attention,
            'today' => [],
            'overdue' => [],
            'commitments' => [],
            'meetings' => [],
            'decisions' => [],
            'people' => [],
            'projects' => [],
            'inbox' => [],
            'risks' => [],
            'followups' => [],
            'fyi' => [],
        ];

        $seen = $attentionKeys;
        foreach ($items as $item) {
            if (isset($seen[$item->dedupeKey])) {
                continue;
            }
            $section = $this->homeSection($item);
            if (! isset($sections[$section])) {
                $section = 'fyi';
            }
            $sections[$section][] = $item->withSection($section);
            $seen[$item->dedupeKey] = true;
        }

        $sections['people'] = $this->peopleFrom($items, $seen, $attentionKeys);
        $sections['projects'] = $this->projectsFrom($items, $seen, $attentionKeys);

        $rendered = [];
        foreach ($sections as $name => $list) {
            $rendered[$name] = array_map(static fn (ExecutiveBriefItem $item): array => $item->toArray(), $list);
        }

        $flat = [];
        foreach ($sections as $list) {
            foreach ($list as $item) {
                $flat[] = $item;
            }
        }

        $top = $flat !== [] ? $flat[0]->score : 0;

        return [
            'sections' => $rendered,
            'attention_count' => count($attention),
            'priority_score' => (int) $top,
            'items' => $flat,
        ];
    }

    /**
     * @param  list<ExecutiveBriefItem>  $items
     * @return list<ExecutiveBriefItem>
     */
    public function cluster(array $items): array
    {
        $emails = [];
        foreach ($items as $item) {
            if ($item->type === 'email_important' || $item->type === 'email_actionable') {
                $emails[] = $item;
            }
        }

        $consumed = [];
        $out = [];
        foreach ($items as $item) {
            if (isset($consumed[$item->dedupeKey])) {
                continue;
            }

            if ($item->commitmentId === null) {
                $out[] = $item;

                continue;
            }

            $match = $this->matchingEmail($item, $emails);
            if ($match === null) {
                $out[] = $item;

                continue;
            }

            $out[] = $item->withSummary($item->summary.' '.$match->summary);
            $consumed[$match->dedupeKey] = true;
        }

        return $out;
    }

    /**
     * @param  list<ExecutiveBriefItem>  $emails
     */
    private function matchingEmail(ExecutiveBriefItem $item, array $emails): ?ExecutiveBriefItem
    {
        $name = mb_strtolower((string) ($item->evidence['person'] ?? ''));
        foreach ($emails as $email) {
            $sender = mb_strtolower((string) ($email->evidence['sender'] ?? $email->title));
            if ($name !== '' && $sender !== '' && str_contains($sender, $name)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * @param  list<ExecutiveBriefItem>  $items
     * @return list<ExecutiveBriefItem>
     */
    public function applyDelta(array $items, ?ExecutiveBrief $previous): array
    {
        $previousKeys = $this->previousKeys($previous);
        $kept = [];
        foreach ($items as $item) {
            $wasSeen = isset($previousKeys[$item->dedupeKey]);
            $unchangedLow = $wasSeen && in_array($item->priority, [ExecutiveBriefPriority::Low, ExecutiveBriefPriority::Normal], true)
                && ! in_array($item->type, ['commitment_overdue', 'integration_blocked', 'meeting_risk', 'commitment_due_today'], true);

            if ($unchangedLow && in_array($item->section, ['inbox', 'fyi', 'today'], true)) {
                continue;
            }

            if ($wasSeen && $item->priority === ExecutiveBriefPriority::Critical) {
                $kept[] = $item->withSummary($item->summary.' (ще не вирішено)');

                continue;
            }

            $kept[] = $item;
        }

        return $kept;
    }

    /**
     * @return array<string, true>
     */
    private function previousKeys(?ExecutiveBrief $previous): array
    {
        if ($previous === null || ! is_array($previous->sections_json)) {
            return [];
        }

        $keys = [];
        foreach ($previous->sections_json as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $row) {
                if (is_array($row) && isset($row['dedupe_key'])) {
                    $keys[(string) $row['dedupe_key']] = true;
                }
            }
        }

        return $keys;
    }

    private function belongsInAttention(ExecutiveBriefItem $item): bool
    {
        return in_array($item->type, [
            'commitment_overdue',
            'integration_blocked',
            'meeting_risk',
            'commitment_detected',
            'leadership_signal',
        ], true) || ($item->type === 'meeting_today' && $item->score >= 80);
    }

    private function homeSection(ExecutiveBriefItem $item): string
    {
        return match ($item->type) {
            'commitment_overdue' => 'overdue',
            'commitment_due_today' => 'today',
            'commitment_due_soon', 'commitment_likely_done', 'commitment_open' => 'commitments',
            'meeting_today', 'calendar_event' => $item->type === 'calendar_event' ? 'today' : 'meetings',
            'meeting_risk' => 'risks',
            'meeting_followup' => 'followups',
            'commitment_detected' => 'decisions',
            'email_important', 'email_actionable' => 'inbox',
            'integration_blocked' => 'attention',
            'automation_failures' => 'risks',
            'leadership_signal' => 'attention',
            default => $item->section,
        };
    }

    /**
     * @param  list<ExecutiveBriefItem>  $items
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $attentionKeys
     * @return list<ExecutiveBriefItem>
     */
    private function peopleFrom(array $items, array &$seen, array $attentionKeys): array
    {
        $byPerson = [];
        foreach ($items as $item) {
            if ($item->personId === null) {
                continue;
            }
            if (! in_array($item->type, ['commitment_overdue', 'commitment_due_today', 'commitment_likely_done'], true)) {
                continue;
            }
            $byPerson[$item->personId][] = $item;
        }

        $out = [];
        foreach ($byPerson as $personId => $group) {
            $key = 'person:'.$personId.':commitments';
            if (isset($seen[$key])) {
                continue;
            }
            $first = $group[0];
            if (isset($attentionKeys[$first->dedupeKey])) {
                continue;
            }
            if (count($group) < 2 && $first->type !== 'commitment_overdue') {
                continue;
            }
            $out[] = new ExecutiveBriefItem(
                type: 'person_followup',
                priority: $first->priority,
                title: $first->title,
                summary: $first->summary,
                section: 'people',
                dedupeKey: $key,
                confidence: 'high',
                score: $first->score - 5,
                actionLabel: $first->actionLabel,
                sourceType: 'person',
                sourceId: (int) $personId,
                personId: (int) $personId,
                deepLink: '/lavr/people',
            );
            $seen[$key] = true;
        }

        return $out;
    }

    /**
     * @param  list<ExecutiveBriefItem>  $items
     * @param  array<string, true>  $seen
     * @param  array<string, true>  $attentionKeys
     * @return list<ExecutiveBriefItem>
     */
    private function projectsFrom(array $items, array &$seen, array $attentionKeys): array
    {
        $byProject = [];
        foreach ($items as $item) {
            if ($item->projectId === null) {
                continue;
            }
            if (! in_array($item->type, ['commitment_overdue', 'meeting_risk', 'commitment_due_today'], true)) {
                continue;
            }
            $byProject[$item->projectId][] = $item;
        }

        $out = [];
        foreach ($byProject as $projectId => $group) {
            $key = 'project:'.$projectId.':notable';
            if (isset($seen[$key])) {
                continue;
            }
            $first = $group[0];
            if (isset($attentionKeys[$first->dedupeKey])) {
                continue;
            }
            $out[] = new ExecutiveBriefItem(
                type: 'project_notable',
                priority: $first->priority,
                title: $first->title,
                summary: $first->summary,
                section: 'projects',
                dedupeKey: $key,
                confidence: 'high',
                score: $first->score - 8,
                sourceType: 'project',
                sourceId: (int) $projectId,
                projectId: (int) $projectId,
                deepLink: '/lavr/projects/'.$projectId,
            );
            $seen[$key] = true;
        }

        return $out;
    }
}
