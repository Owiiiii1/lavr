<?php

namespace App\Services\Synthesis;

final class SynthesisToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'get_synthesis',
            'get_project_status',
            'get_person_status',
            'list_waiting_for',
            'list_commitments',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Cross-source synthesis is a derived picture over Knowledge, Tasks, Reminders, Watchers, and Projects. It is not Memory, not the Knowledge graph, and not live Gmail/Calendar/GitHub.',
            'get_project_context remains raw/derived project context. get_project_status is current cross-source state (blockers, waiting, open work). Use get_project_status for “что по YFS / что блокирует”.',
            'get_synthesis(type=recent_changes|attention_needed|waiting_for|blockers|daily_digest|weekly_digest) answers what changed, what stalled, waiting-for, and digests. time_window like 7d is optional.',
            'list_waiting_for is an explicit loop. list_commitments reads first-class Commitments when they exist; Knowledge is fallback only if that table is empty. Vague language is not a commitment.',
            'get_person_status uses canonical People, then first-class commitments, projects, recent meetings, then Knowledge. Foreign ids fail.',
            'Indexed data may be stale. If the user asks what is happening now in Gmail/Calendar/GitHub and freshness.integrations.*.stale is true, call the live integration tool. Synthesis itself must not poll those APIs.',
            'Never create watchers, tasks, reminders, or send mail from synthesis. You may suggest a watcher for a repeated wait, or a reminder for a known time, and wait for the user.',
            'If conflicts[] is present, say there is contradictory data and cite sources. Do not pick an AI-preferred fact.',
        ];
    }
}
