<?php

namespace App\Services\OperationalControl;

use App\Enums\OperationalEventStatus;
use App\Enums\OperationalSeverity;
use App\Models\OperationalEvent;
use App\Models\User;

final class OperationalEventRecorder
{
    public function record(User $user, OperationalRuleMatch $match): OperationalEvent
    {
        $event = OperationalEvent::query()->firstOrNew([
            'user_id' => $user->id,
            'fingerprint' => $match->fingerprint,
        ]);

        $existingSeverity = $event->exists && $event->severity instanceof OperationalSeverity
            ? $event->severity
            : $match->severity;
        $severity = OperationalSeverity::higher($existingSeverity, $match->severity);

        $status = $event->status instanceof OperationalEventStatus
            ? $event->status
            : OperationalEventStatus::Observed;

        if (! $event->exists) {
            $status = OperationalEventStatus::Observed;
        } elseif ($status === OperationalEventStatus::Resolved || $status === OperationalEventStatus::Superseded || $status === OperationalEventStatus::Dismissed) {
            $status = $event->status;
        }

        $event->fill([
            'event_type' => $match->eventType,
            'occurred_at' => $match->occurredAt,
            'source_type' => $match->sourceType,
            'source_id' => $match->sourceId,
            'source_external_id' => $match->sourceExternalId,
            'person_id' => $match->personId,
            'project_id' => $match->projectId,
            'meeting_id' => $match->meetingId,
            'commitment_id' => $match->commitmentId,
            'severity' => $severity,
            'payload_json' => $this->minimalPayload($match),
            'status' => $status,
            'confidence' => $match->confidence,
            'evidence_pointer' => $match->evidencePointer,
        ]);
        $event->save();

        return $event->fresh() ?? $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPayload(OperationalRuleMatch $match): array
    {
        $payload = $match->payload;
        unset($payload['body'], $payload['transcript'], $payload['raw']);

        return [
            'rationale' => mb_substr($match->rationale, 0, 400),
            'evidence' => $match->evidence,
            'href' => $match->href,
            ...$payload,
        ];
    }
}
