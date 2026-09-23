<?php

namespace App\Services\Meetings;

final class MeetingIntelligencePrompt
{
    public function systemPrompt(): string
    {
        return <<<'TXT'
You extract Meeting Intelligence from a transcript. Return one JSON object only. Do not invent facts.

Rules:
- unknown → null or omit the field
- deadline_at only if an explicit calendar date exists in the text, or a relative phrase can be resolved because meeting_started_at is provided
- if meeting_started_at is null, keep deadline_raw and set deadline_at to null for relative phrases (tomorrow, next week, до п'ятниці)
- owner / person only if identifiable from the transcript
- no invented names, projects, or completion statuses
- do not create first-class commitments; commitments_detected is analysis only
- action_items are tasks; commitments_detected are promises a person made; do not mix them
- evidence excerpts must be short quotes from the transcript
- confidence is high, medium, or low
- summary.outcomes: 3–7 concise bullets; summary.executive: short paragraph; summary.attention: items that need Owner attention
- read the chunk in time order. A later decision supersedes an earlier hypothesis, open question, or risk about the same topic
- mark superseded items with "state": "superseded" and "resolved_by_later_context": true
- kind is one of: decision, action, commitment, risk, question, hypothesis, brainstorm, discussion_only
- hypothesis, brainstorm, and discussion_only are not current outcomes
- do not put the same promise in both action_items and commitments_detected
- severity on risks is high, medium, or low
- do not evaluate personality and do not invent a leadership score

JSON shape:
{
  "summary": {"executive": string, "outcomes": [string], "attention": [string]},
  "participants": [string],
  "topics": [string],
  "decisions": [{"text": string, "kind": "decision", "state": "current", "confidence": "high|medium|low", "evidence": {"excerpt": string, "speaker": string|null, "timestamp": string|null}}],
  "action_items": [{"owner": string|null, "task": string, "kind": "action", "state": "current", "deadline_raw": string|null, "deadline_at": string|null, "status": "detected", "confidence": "high|medium|low", "evidence": {"excerpt": string, "speaker": string|null, "timestamp": string|null}}],
  "commitments_detected": [{"person_name": string|null, "person_ref": string|null, "action": string, "expected_result": string|null, "deadline_raw": string|null, "deadline_at": string|null, "confidence": "high|medium|low", "evidence": {"excerpt": string, "speaker": string|null, "timestamp": string|null}}],
  "deadlines": [{"text": string, "deadline_raw": string|null, "deadline_at": string|null, "owner": string|null, "confidence": "high|medium|low", "evidence": {"excerpt": string, "speaker": string|null, "timestamp": string|null}}],
  "open_questions": [{"text": string, "confidence": "high|medium|low"}],
  "risks": [{"text": string, "confidence": "high|medium|low"}],
  "follow_ups": [{"text": string, "confidence": "high|medium|low"}],
  "unresolved_identities": [string],
  "likely_project": string|null,
  "source_references": [string]
}
TXT;
    }

    /**
     * @param  list<string>  $knownPeople
     * @param  list<string>  $knownProjects
     */
    public function userPrompt(
        string $transcript,
        ?string $title,
        ?string $startedAt,
        array $knownPeople,
        array $knownProjects,
        int $chunkIndex,
        int $chunkCount,
    ): string {
        $people = $knownPeople === [] ? '(none)' : implode(', ', $knownPeople);
        $projects = $knownProjects === [] ? '(none)' : implode(', ', $knownProjects);

        return 'Meeting title: '.($title ?: '(unknown)')."\n"
            .'meeting_started_at: '.($startedAt ?: 'null')."\n"
            ."Known people (do not invent others): {$people}\n"
            ."Known projects (suggestion only, do not assign): {$projects}\n"
            ."Chunk {$chunkIndex} of {$chunkCount}. Preserve speaker labels and timestamps from the transcript.\n\n"
            ."TRANSCRIPT:\n{$transcript}";
    }
}
