<?php

namespace App\Services\Zoom;

use App\Enums\ZoomWebhookEventStatus;
use App\Models\ZoomWebhookEvent;
use App\Services\Zoom\Exceptions\ZoomException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

final class ZoomWebhookService
{
    public const TRANSCRIPT_COMPLETED = 'recording.transcript_completed';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function persist(array $payload): ZoomWebhookEvent
    {
        $eventName = trim((string) ($payload['event'] ?? ''));
        $object = is_array($payload['payload']['object'] ?? null) ? $payload['payload']['object'] : [];
        $accountId = trim((string) ($payload['payload']['account_id'] ?? $object['account_id'] ?? ''));
        $uuid = $this->meetingUuid($object);
        $numericId = $this->meetingNumericId($object);
        $recordingId = $this->transcriptRecordingId($object);
        $eventTs = (string) ($payload['event_ts'] ?? '');
        $key = implode('|', [
            $eventName,
            $eventTs,
            $accountId,
            $uuid,
            $recordingId,
        ]);

        try {
            return ZoomWebhookEvent::query()->create([
                'external_event_key' => mb_substr($key, 0, 190),
                'event_name' => mb_substr($eventName, 0, 190),
                'account_id' => $accountId !== '' ? $accountId : null,
                'meeting_uuid' => $uuid !== '' ? $uuid : null,
                'meeting_numeric_id' => $numericId !== '' ? $numericId : null,
                'recording_file_id' => $recordingId !== '' ? $recordingId : null,
                'status' => ZoomWebhookEventStatus::Received,
                'payload_subset' => $this->subset($payload, $object),
                'received_at' => Carbon::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = ZoomWebhookEvent::query()->where('external_event_key', mb_substr($key, 0, 190))->first();

            if ($existing === null) {
                throw new ZoomException('duplicate_event', 'Zoom webhook was already stored.', false);
            }

            return $existing;
        }
    }

    public function isTranscriptCompleted(string $eventName): bool
    {
        return in_array($eventName, [
            self::TRANSCRIPT_COMPLETED,
            'recording.transcript.completed',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public function meetingUuid(array $object): string
    {
        return trim((string) ($object['uuid'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public function meetingNumericId(array $object): string
    {
        $id = $object['id'] ?? null;

        if (is_int($id) || is_float($id)) {
            return (string) $id;
        }

        return is_string($id) ? trim($id) : '';
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public function transcriptRecordingId(array $object): string
    {
        $files = is_array($object['recording_files'] ?? null) ? $object['recording_files'] : [];

        foreach ($files as $file) {
            if (! is_array($file)) {
                continue;
            }

            $type = strtoupper((string) ($file['file_type'] ?? ''));
            $recordingType = strtolower((string) ($file['recording_type'] ?? ''));

            if ($type === 'TRANSCRIPT' || $recordingType === 'audio_transcript') {
                return trim((string) ($file['id'] ?? ''));
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function subset(array $payload, array $object): array
    {
        $files = [];
        $recordingFiles = is_array($object['recording_files'] ?? null) ? $object['recording_files'] : [];

        foreach ($recordingFiles as $file) {
            if (! is_array($file)) {
                continue;
            }

            $files[] = [
                'id' => $file['id'] ?? null,
                'file_type' => $file['file_type'] ?? null,
                'file_extension' => $file['file_extension'] ?? null,
                'recording_type' => $file['recording_type'] ?? null,
                'status' => $file['status'] ?? null,
            ];
        }

        return [
            'event' => $payload['event'] ?? null,
            'event_ts' => $payload['event_ts'] ?? null,
            'account_id' => $payload['payload']['account_id'] ?? null,
            'id' => $object['id'] ?? null,
            'uuid' => $object['uuid'] ?? null,
            'topic' => $object['topic'] ?? null,
            'start_time' => $object['start_time'] ?? null,
            'timezone' => $object['timezone'] ?? null,
            'duration' => $object['duration'] ?? null,
            'host_id' => $object['host_id'] ?? null,
            'host_email' => $object['host_email'] ?? null,
            'type' => $object['type'] ?? null,
            'recording_files' => $files,
        ];
    }
}
