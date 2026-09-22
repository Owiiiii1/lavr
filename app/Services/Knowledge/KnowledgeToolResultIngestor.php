<?php

namespace App\Services\Knowledge;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeSourceType;
use App\Models\User;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Users\UserCapability;
use Throwable;

final class KnowledgeToolResultIngestor
{
    public function __construct(
        private readonly KnowledgeIngestionService $ingestion = new KnowledgeIngestionService,
    ) {}

    public function ingest(ToolExecutionContext $context, ToolResult $result): void
    {
        if (! (bool) config('knowledge.ingest_tool_results', true)) {
            return;
        }

        if (! $result->success || ! $context->user->canUseCapability(UserCapability::KNOWLEDGE)) {
            return;
        }

        try {
            match ($result->name) {
                'get_gmail_message', 'search_gmail' => $this->gmail($context->user, $context->conversation->id, $result),
                'get_calendar_event', 'list_calendar_events', 'search_calendar_events' => $this->calendar($context->user, $context->conversation->id, $result),
                default => null,
            };
        } catch (Throwable) {
        }
    }

    private function gmail(User $user, int $conversationId, ToolResult $result): void
    {
        $from = $this->stringAt($result->payload, ['from', 'sender', 'from_name']);

        if ($from === null) {
            $messages = $result->payload['messages'] ?? $result->payload['results'] ?? [];

            if (is_array($messages) && isset($messages[0]) && is_array($messages[0])) {
                $from = $this->stringAt($messages[0], ['from', 'from_name', 'sender']);
            }
        }

        if ($from === null) {
            return;
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Gmail,
            fingerprint: KnowledgeSourceRef::hash('gmail', (string) $user->id, $from, (string) ($result->payload['id'] ?? $result->payload['message_id'] ?? $from)),
            confidence: KnowledgeConfidence::deterministic(),
            conversationId: $conversationId,
        );
        $person = $this->ingestion->upsertEntity($user, KnowledgeEntityType::Person, $from, $source);
        $this->ingestion->recordEvent($user, KnowledgeEventType::EmailReceived, 'Email involving '.$from, $source, [$person]);
    }

    private function calendar(User $user, int $conversationId, ToolResult $result): void
    {
        $title = $this->stringAt($result->payload, ['title', 'summary']);
        $attendee = $this->stringAt($result->payload, ['organizer', 'attendee']);

        if ($title === null) {
            return;
        }

        $source = new KnowledgeSourceRef(
            type: KnowledgeSourceType::Calendar,
            fingerprint: KnowledgeSourceRef::hash('calendar', (string) $user->id, (string) ($result->payload['id'] ?? $result->payload['calendar_event_id'] ?? $title)),
            confidence: KnowledgeConfidence::deterministic(),
            conversationId: $conversationId,
        );
        $entities = [
            $this->ingestion->upsertEntity($user, KnowledgeEntityType::Topic, $title, $source),
        ];

        if ($attendee !== null) {
            $entities[] = $this->ingestion->upsertEntity($user, KnowledgeEntityType::Person, $attendee, $source);
        }

        $this->ingestion->recordEvent($user, KnowledgeEventType::CalendarEvent, $title, $source, $entities);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function stringAt(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                return mb_substr(trim($payload[$key]), 0, 120);
            }
        }

        return null;
    }
}
