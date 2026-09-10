<?php

namespace App\Services\ExecutiveBrief;

use App\Enums\ExecutiveBriefPriority;

final readonly class ExecutiveBriefItem
{
    /**
     * @param  array<string, mixed>  $evidence
     */
    public function __construct(
        public string $type,
        public ExecutiveBriefPriority $priority,
        public string $title,
        public string $summary,
        public string $section,
        public string $dedupeKey,
        public string $confidence,
        public int $score,
        public ?string $actionLabel = null,
        public ?string $sourceType = null,
        public ?int $sourceId = null,
        public ?int $personId = null,
        public ?int $projectId = null,
        public ?int $meetingId = null,
        public ?int $commitmentId = null,
        public ?string $dueAt = null,
        public ?string $deepLink = null,
        public array $evidence = [],
        public bool $bodyRead = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'priority' => $this->priority->value,
            'title' => $this->title,
            'summary' => $this->summary,
            'section' => $this->section,
            'dedupe_key' => $this->dedupeKey,
            'confidence' => $this->confidence,
            'score' => $this->score,
            'action_label' => $this->actionLabel,
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'person_id' => $this->personId,
            'project_id' => $this->projectId,
            'meeting_id' => $this->meetingId,
            'commitment_id' => $this->commitmentId,
            'due_at' => $this->dueAt,
            'deep_link' => $this->deepLink,
            'evidence' => $this->evidence,
            'body_read' => $this->bodyRead,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            type: (string) ($row['type'] ?? 'fyi'),
            priority: ExecutiveBriefPriority::tryFrom((string) ($row['priority'] ?? '')) ?? ExecutiveBriefPriority::Normal,
            title: (string) ($row['title'] ?? ''),
            summary: (string) ($row['summary'] ?? ''),
            section: (string) ($row['section'] ?? 'fyi'),
            dedupeKey: (string) ($row['dedupe_key'] ?? ''),
            confidence: (string) ($row['confidence'] ?? 'medium'),
            score: (int) ($row['score'] ?? 0),
            actionLabel: isset($row['action_label']) ? (string) $row['action_label'] : null,
            sourceType: isset($row['source_type']) ? (string) $row['source_type'] : null,
            sourceId: isset($row['source_id']) ? (int) $row['source_id'] : null,
            personId: isset($row['person_id']) ? (int) $row['person_id'] : null,
            projectId: isset($row['project_id']) ? (int) $row['project_id'] : null,
            meetingId: isset($row['meeting_id']) ? (int) $row['meeting_id'] : null,
            commitmentId: isset($row['commitment_id']) ? (int) $row['commitment_id'] : null,
            dueAt: isset($row['due_at']) ? (string) $row['due_at'] : null,
            deepLink: isset($row['deep_link']) ? (string) $row['deep_link'] : null,
            evidence: is_array($row['evidence'] ?? null) ? $row['evidence'] : [],
            bodyRead: (bool) ($row['body_read'] ?? true),
        );
    }

    public function withSection(string $section): self
    {
        return new self(
            $this->type,
            $this->priority,
            $this->title,
            $this->summary,
            $section,
            $this->dedupeKey,
            $this->confidence,
            $this->score,
            $this->actionLabel,
            $this->sourceType,
            $this->sourceId,
            $this->personId,
            $this->projectId,
            $this->meetingId,
            $this->commitmentId,
            $this->dueAt,
            $this->deepLink,
            $this->evidence,
            $this->bodyRead,
        );
    }

    public function withSummary(string $summary): self
    {
        return new self(
            $this->type,
            $this->priority,
            $this->title,
            $summary,
            $this->section,
            $this->dedupeKey,
            $this->confidence,
            $this->score,
            $this->actionLabel,
            $this->sourceType,
            $this->sourceId,
            $this->personId,
            $this->projectId,
            $this->meetingId,
            $this->commitmentId,
            $this->dueAt,
            $this->deepLink,
            $this->evidence,
            $this->bodyRead,
        );
    }

    public function withEvidencePerson(string $name): self
    {
        $evidence = $this->evidence;
        $evidence['person'] = $name;

        return new self(
            $this->type,
            $this->priority,
            $this->title,
            $this->summary,
            $this->section,
            $this->dedupeKey,
            $this->confidence,
            $this->score,
            $this->actionLabel,
            $this->sourceType,
            $this->sourceId,
            $this->personId,
            $this->projectId,
            $this->meetingId,
            $this->commitmentId,
            $this->dueAt,
            $this->deepLink,
            $evidence,
            $this->bodyRead,
        );
    }
}
