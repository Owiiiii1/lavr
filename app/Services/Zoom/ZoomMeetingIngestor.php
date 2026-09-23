<?php

namespace App\Services\Zoom;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingArtifactKind;
use App\Enums\MeetingSourceType;
use App\Enums\MeetingStatus;
use App\Enums\ZoomImportStatus;
use App\Enums\ZoomWebhookEventStatus;
use App\Models\Meeting;
use App\Models\User;
use App\Models\ZoomWebhookEvent;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\ParticipantResolver;
use App\Services\Zoom\Exceptions\ZoomException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ZoomMeetingIngestor
{
    public function __construct(
        private readonly ZoomCredentialService $credentials,
        private readonly ZoomOAuthClient $oauth,
        private readonly ZoomTranscriptClient $transcripts,
        private readonly MeetingService $meetings,
        private readonly ParticipantResolver $participants,
    ) {}

    public function ingest(ZoomWebhookEvent $event): Meeting
    {
        $account = $this->credentials->configuredAccount();

        if ($account === null || ! $this->credentials->hasOAuthCredentials($account)) {
            throw new ZoomException('blocked_auth', 'Zoom integration is not configured.', false);
        }

        if (! $this->credentials->isEnabled($account)) {
            throw new ZoomException('disabled', 'Zoom integration is disabled.', false);
        }

        $uuid = trim((string) $event->meeting_uuid);

        if ($uuid === '') {
            throw new ZoomException('missing_meeting', 'Zoom meeting UUID is missing.', false);
        }

        $token = $this->oauth->accessToken($account);
        $metadata = $this->transcripts->fetchTranscriptMetadata($token, $uuid);
        $body = $this->transcripts->download($token, $metadata['download_url']);
        $owner = $account->user;

        if (! $owner instanceof User) {
            throw new ZoomException('blocked_auth', 'Zoom integration owner is missing.', false);
        }

        $meeting = DB::transaction(function () use ($owner, $event, $metadata): Meeting {
            return $this->resolveMeeting($owner, $event, $metadata);
        });

        $this->markImport($meeting, ZoomImportStatus::Downloading, $event);

        $artifact = $this->meetings->storeImportedText(
            $owner,
            $meeting,
            $body,
            $this->extensionFromTranscript($body),
            'zoom-transcript.vtt',
            MeetingArtifactKind::OriginalFile,
        );

        $this->seedHost($owner, $meeting, $event);

        $this->markImport($meeting, ZoomImportStatus::Processing, $event, [
            'artifact_id' => $artifact->id,
        ]);

        $this->meetings->dispatchAnalysis($meeting);

        $this->markImport($meeting, ZoomImportStatus::Completed, $event, [
            'artifact_id' => $artifact->id,
        ]);

        $event->forceFill([
            'status' => ZoomWebhookEventStatus::Processed,
            'meeting_id' => $meeting->id,
            'processed_at' => now(),
            'error_class' => null,
            'error_message' => null,
            'attempts' => $event->attempts + 1,
        ])->save();

        $this->credentials->mergeMetadata($account, [
            'last_import_at' => now()->toIso8601String(),
            'last_import_meeting_id' => $meeting->id,
        ]);

        Log::info('zoom transcript ingested', [
            'zoom_event_id' => $event->id,
            'meeting_id' => $meeting->id,
            'artifact_id' => $artifact->id,
            'meeting_uuid_hash' => substr(hash('sha256', $uuid), 0, 12),
            'checksum' => $artifact->checksum_sha256,
            'analysis_dispatched' => true,
        ]);

        return $meeting->fresh() ?? $meeting;
    }

    public function retryMeeting(Meeting $meeting): Meeting
    {
        $event = ZoomWebhookEvent::query()
            ->where('meeting_id', $meeting->id)
            ->orderByDesc('id')
            ->first();

        if ($event === null && is_string($meeting->source_external_id) && $meeting->source_external_id !== '') {
            $event = ZoomWebhookEvent::query()
                ->where('meeting_uuid', $meeting->source_external_id)
                ->orderByDesc('id')
                ->first();
        }

        if ($event === null) {
            throw new ZoomException('missing_meeting', 'No Zoom webhook event is available to retry.', false);
        }

        return $this->ingest($event);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function resolveMeeting(User $owner, ZoomWebhookEvent $event, array $metadata): Meeting
    {
        $uuid = (string) $event->meeting_uuid;
        $existing = Meeting::query()
            ->where('user_id', $owner->id)
            ->where('source_type', MeetingSourceType::Zoom)
            ->where('source_external_id', $uuid)
            ->lockForUpdate()
            ->first();

        $subset = is_array($event->payload_subset) ? $event->payload_subset : [];
        $title = $this->nullableString($subset['topic'] ?? null)
            ?? $this->nullableString($metadata['meeting_topic'] ?? null)
            ?? 'Zoom meeting';
        $startedAt = $this->nullableDate($subset['start_time'] ?? null);
        $endedAt = $this->endedAt($subset, $startedAt);
        $timezone = $this->nullableString($subset['timezone'] ?? null) ?? $owner->timezone;

        if ($existing !== null) {
            $updates = [
                'metadata' => $this->zoomMetadata($existing, $event, ZoomImportStatus::Pending),
            ];

            if ($existing->started_at === null && $startedAt !== null) {
                $updates['started_at'] = $startedAt;
            }

            if ($existing->ended_at === null && $endedAt !== null) {
                $updates['ended_at'] = $endedAt;
            }

            if ($existing->timezone === null && $timezone !== null) {
                $updates['timezone'] = $timezone;
            }

            $existing->forceFill($updates)->save();

            return $existing;
        }

        $created = Meeting::query()->create([
            'user_id' => $owner->id,
            'project_id' => null,
            'organization_id' => null,
            'title' => mb_substr($title, 0, 190),
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'timezone' => $timezone,
            'source_type' => MeetingSourceType::Zoom,
            'source_external_id' => $uuid,
            'status' => MeetingStatus::Ready,
            'analysis_status' => MeetingAnalysisStatus::Pending,
            'metadata' => $this->zoomMetadata(null, $event, ZoomImportStatus::Pending),
        ]);

        return $this->meetings->applyDefaultReviewSubject($owner, $created);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function markImport(Meeting $meeting, ZoomImportStatus $status, ZoomWebhookEvent $event, array $extra = []): void
    {
        $metadata = is_array($meeting->metadata) ? $meeting->metadata : [];
        $zoom = is_array($metadata['zoom'] ?? null) ? $metadata['zoom'] : [];
        $metadata['zoom'] = array_merge($zoom, [
            'import_status' => $status->value,
            'meeting_uuid' => $event->meeting_uuid,
            'meeting_numeric_id' => $event->meeting_numeric_id,
            'recording_file_id' => $event->recording_file_id,
            'webhook_event_id' => $event->id,
            'last_error' => $status === ZoomImportStatus::Failed || $status === ZoomImportStatus::BlockedAuth || $status === ZoomImportStatus::TranscriptUnavailable
                ? ($extra['last_error'] ?? null)
                : null,
        ], $extra);

        $meeting->forceFill(['metadata' => $metadata])->save();
    }

    public function markFailure(Meeting $meeting, ZoomImportStatus $status, string $error): void
    {
        $metadata = is_array($meeting->metadata) ? $meeting->metadata : [];
        $zoom = is_array($metadata['zoom'] ?? null) ? $metadata['zoom'] : [];
        $zoom['import_status'] = $status->value;
        $zoom['last_error'] = $error;
        $metadata['zoom'] = $zoom;
        $meeting->forceFill(['metadata' => $metadata])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function zoomMetadata(?Meeting $meeting, ZoomWebhookEvent $event, ZoomImportStatus $status): array
    {
        $metadata = is_array($meeting?->metadata) ? $meeting->metadata : [];
        $zoom = is_array($metadata['zoom'] ?? null) ? $metadata['zoom'] : [];
        $subset = is_array($event->payload_subset) ? $event->payload_subset : [];

        $metadata['zoom'] = array_merge($zoom, [
            'import_status' => $status->value,
            'meeting_uuid' => $event->meeting_uuid,
            'meeting_numeric_id' => $event->meeting_numeric_id,
            'recording_file_id' => $event->recording_file_id,
            'host_id' => $subset['host_id'] ?? null,
            'webhook_event_id' => $event->id,
        ]);

        return $metadata;
    }

    private function seedHost(User $owner, Meeting $meeting, ZoomWebhookEvent $event): void
    {
        $subset = is_array($event->payload_subset) ? $event->payload_subset : [];
        $email = $this->nullableString($subset['host_email'] ?? null);
        $name = $this->nullableString($subset['host_name'] ?? null) ?? $email;

        if ($name === null && $email === null) {
            return;
        }

        $this->participants->upsertParticipant(
            $owner,
            $meeting,
            $name ?? (string) $email,
            $email,
            'zoom-host',
        );
    }

    /**
     * @param  array<string, mixed>  $subset
     */
    private function endedAt(array $subset, mixed $startedAt): mixed
    {
        $end = $this->nullableDate($subset['end_time'] ?? null);

        if ($end !== null) {
            return $end;
        }

        $duration = isset($subset['duration']) ? (int) $subset['duration'] : 0;

        if ($startedAt instanceof CarbonInterface && $duration > 0) {
            return $startedAt->copy()->addMinutes($duration);
        }

        return null;
    }

    private function extensionFromTranscript(string $body): string
    {
        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, 'WEBVTT')) {
            return 'vtt';
        }

        if (preg_match('/^\d+\s+\d{2}:\d{2}:\d{2},\d{3}/', $trimmed) === 1) {
            return 'srt';
        }

        return 'vtt';
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableDate(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
