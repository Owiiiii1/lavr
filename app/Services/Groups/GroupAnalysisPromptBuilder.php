<?php

namespace App\Services\Groups;

final class GroupAnalysisPromptBuilder
{
    /**
     * @param  list<string>  $lines
     */
    public function chunk(array $lines): string
    {
        return implode("\n", [
            ...$this->instructions(),
            'Transcript (analyze only these messages):',
            ...$lines,
        ]);
    }

    /**
     * @param  list<string>  $chunkJson
     */
    public function reduce(array $chunkJson): string
    {
        $blocks = [];

        foreach ($chunkJson as $index => $json) {
            $blocks[] = 'Chunk '.($index + 1).':';
            $blocks[] = $json;
        }

        return implode("\n", [
            ...$this->instructions(),
            'You are reducing chunk analyses of the same Telegram group range.',
            'Merge duplicates. Keep provenance source_message_ids as the union of valid ids.',
            'Do not invent decisions, tasks, or events that are absent from the chunks.',
            'If two chunks describe the same fact that later changed, keep the later fact and set supersedes_normalized_key on the new item when possible.',
            'Chunk analyses:',
            ...$blocks,
        ]);
    }

    /**
     * @return list<string>
     */
    private function instructions(): array
    {
        return [
            'Task: extract group knowledge from supplied Telegram group messages.',
            'Return JSON only. No markdown. No commentary.',
            'Schema:',
            '{"summary":{"content":"string","confidence":0.0,"source_message_ids":[1]},"decisions":[{"content":"string","confidence":0.0,"source_message_ids":[1],"participants":[],"effective_date_local":null,"supersedes_normalized_key":null,"thread_id":null}],"tasks":[{"content":"string","assignee_text":null,"due_at_local":null,"status_hint":null,"confidence":0.0,"source_message_ids":[1],"supersedes_normalized_key":null,"thread_id":null}],"events":[{"content":"string","occurred_at_local":null,"confidence":0.0,"source_message_ids":[1],"supersedes_normalized_key":null,"thread_id":null}]}',
            'Rules:',
            '- Summarise only the supplied messages. Do not invent facts.',
            '- Summary must be concise: main topics, key developments, unresolved questions. Not a transcript.',
            '- A Decision requires clear agreement or an explicit decision. Questions, suggestions, and "maybe" are not decisions.',
            '- A Task is a concrete action item. assignee_text is a display name from the transcript only. Never invent a LAVR user_id.',
            '- An Event/Fact is a notable state change (release, outage, delivery, meeting result). Do not turn every reply into an event.',
            '- Distinguish LAVR/bot lines (is_bot=true, sender=LAVR) from human participants. Bot text is not automatically authoritative.',
            '- Preserve uncertainty. Use only message ids from the supplied transcript.',
            '- Confidence is 0..1. Local dates use the group timezone already applied in occurred timestamps.',
            '- Do not extract personal memory. Do not output user_id or telegram numeric sender ids.',
            '- Empty arrays are required when nothing qualifies.',
        ];
    }
}
