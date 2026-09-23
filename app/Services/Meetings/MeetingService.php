<?php

namespace App\Services\Meetings;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingArtifactKind;
use App\Enums\MeetingLeadershipReviewStatus;
use App\Enums\MeetingSourceType;
use App\Enums\MeetingStatus;
use App\Jobs\AnalyzeMeetingTranscriptJob;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\MeetingArtifact;
use App\Models\MeetingParticipant;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Meetings\Exceptions\MeetingException;
use App\Services\Users\UserCapability;
use App\Services\Zoom\ZoomMeetingIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MeetingService
{
    public function __construct(
        private readonly TranscriptNormalizer $normalizer,
        private readonly ParticipantResolver $participants,
        private readonly DirectoryService $directory,
        private readonly MeetingReviewService $reviews,
    ) {}

    /**
     * @return Collection<int, Meeting>
     */
    public function list(
        User $user,
        ?string $query = null,
        ?int $projectId = null,
        ?string $from = null,
        ?string $to = null,
        ?string $analysisStatus = null,
        ?string $status = null,
    ): Collection {
        $this->assertCapability($user);

        $builder = Meeting::query()
            ->with(['project:id,name', 'organization:id,name', 'participants'])
            ->withCount('participants')
            ->where('user_id', $user->id)
            ->orderByDesc('started_at')
            ->orderByDesc('id');

        if ($projectId) {
            $builder->where('project_id', $projectId);
        }

        if ($analysisStatus !== null && $analysisStatus !== '') {
            $builder->where('analysis_status', $analysisStatus);
        }

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        } else {
            $builder->where('status', '!=', MeetingStatus::Archived);
        }

        if ($from !== null && $from !== '') {
            $builder->whereDate('started_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $builder->whereDate('started_at', '<=', $to);
        }

        if ($query !== null && trim($query) !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($query)).'%';
            $builder->where(function ($inner) use ($needle): void {
                $inner->where('title', 'like', $needle)
                    ->orWhere('summary', 'like', $needle)
                    ->orWhereHas('project', fn ($project) => $project->where('name', 'like', $needle))
                    ->orWhereHas('participants', fn ($participant) => $participant->where('display_name', 'like', $needle));
            });
        }

        return $builder->limit(200)->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createManual(User $user, array $payload, ?UploadedFile $file = null, ?string $pastedText = null): Meeting
    {
        $this->assertCapability($user);
        $hasFile = $file !== null;
        $paste = is_string($pastedText) ? trim($pastedText) : '';

        if (! $hasFile && $paste === '') {
            throw new MeetingException('empty_transcript', 'Upload a transcript file or paste transcript text.');
        }

        if ($hasFile && $paste !== '') {
            throw new MeetingException('ambiguous_source', 'Use either a file or pasted text, not both.');
        }

        $meeting = DB::transaction(function () use ($user, $payload, $hasFile, $file, $paste): Meeting {
            $meeting = Meeting::query()->create([
                'user_id' => $user->id,
                'project_id' => $this->ownedProjectId($user, $payload['project_id'] ?? null),
                'organization_id' => $this->ownedOrganizationId($user, $payload['organization_id'] ?? null),
                'title' => $this->titleFrom($payload, $file, $paste),
                'meeting_type' => $this->nullableString($payload['meeting_type'] ?? null),
                'started_at' => $this->nullableDate($payload['started_at'] ?? null),
                'ended_at' => $this->nullableDate($payload['ended_at'] ?? null),
                'timezone' => $this->nullableString($payload['timezone'] ?? null) ?? $user->timezone,
                'location' => $this->nullableString($payload['location'] ?? null),
                'source_type' => $hasFile ? MeetingSourceType::ManualUpload : MeetingSourceType::ManualText,
                'source_external_id' => $this->nullableString($payload['source_external_id'] ?? null),
                'status' => MeetingStatus::Ready,
                'analysis_status' => MeetingAnalysisStatus::Pending,
                'notes' => $this->nullableString($payload['notes'] ?? null),
            ]);

            $this->applyReviewChoice($user, $meeting, $payload);

            if ($hasFile) {
                $this->storeUploadedFile($user, $meeting, $file);
            } else {
                $this->storePastedText($user, $meeting, $paste);
            }

            return $meeting;
        });

        $this->dispatchAnalysis($meeting);

        return $meeting->fresh(['project', 'organization', 'participants', 'artifacts']) ?? $meeting;
    }

    /**
     * A meeting that has not happened yet: no transcript, optional calendar event,
     * ready to receive the Zoom recording or notes later.
     *
     * @param  array<string, mixed>  $payload
     */
    public function createPlanned(User $user, array $payload): Meeting
    {
        $this->assertCapability($user);

        $title = $this->nullableString($payload['title'] ?? null);

        if ($title === null) {
            throw new MeetingException('missing_title', 'A planned meeting needs a title.');
        }

        $timezone = $this->nullableString($payload['timezone'] ?? null) ?? (string) ($user->timezone ?: config('app.timezone'));
        $startedAt = $this->plannedMoment($payload['started_at'] ?? null, $timezone);

        if ($startedAt === null) {
            throw new MeetingException('missing_start', 'A planned meeting needs a start time.');
        }

        $endedAt = $this->plannedMoment($payload['ended_at'] ?? null, $timezone);

        if ($endedAt !== null && $endedAt->lessThanOrEqualTo($startedAt)) {
            throw new MeetingException('invalid_range', 'The meeting must end after it starts.');
        }

        $calendar = $this->calendarReference($payload);

        $meeting = DB::transaction(function () use ($user, $payload, $title, $startedAt, $endedAt, $timezone, $calendar): Meeting {
            $meeting = Meeting::query()->create([
                'user_id' => $user->id,
                'project_id' => $this->ownedProjectId($user, $payload['project_id'] ?? null),
                'organization_id' => $this->ownedOrganizationId($user, $payload['organization_id'] ?? null),
                'title' => $title,
                'meeting_type' => $this->nullableString($payload['meeting_type'] ?? null),
                'started_at' => $startedAt,
                'ended_at' => $endedAt,
                'timezone' => $timezone,
                'location' => $this->nullableString($payload['location'] ?? null),
                'source_type' => MeetingSourceType::CalendarLink,
                'source_external_id' => $this->nullableString($payload['source_external_id'] ?? null),
                'calendar_provider' => $calendar['provider'],
                'calendar_id' => $calendar['calendar_id'],
                'calendar_event_id' => $calendar['event_id'],
                'status' => MeetingStatus::Draft,
                'analysis_status' => MeetingAnalysisStatus::Pending,
                'notes' => $this->nullableString($payload['notes'] ?? null),
            ]);

            $this->participants->seedFromTranscript($user, $meeting, [], $this->plannedParticipants($payload));

            return $meeting;
        });

        return $meeting->fresh(['project', 'organization', 'participants']) ?? $meeting;
    }

    public function linkCalendarEvent(User $user, Meeting $meeting, string $provider, string $calendarId, string $eventId): Meeting
    {
        $this->owned($user, $meeting);

        $calendar = $this->calendarReference([
            'calendar_provider' => $provider,
            'calendar_id' => $calendarId,
            'calendar_event_id' => $eventId,
        ]);

        if ($calendar['provider'] === null) {
            throw new MeetingException('invalid_calendar_reference', 'Provide provider, calendar id and event id.');
        }

        $meeting->forceFill([
            'calendar_provider' => $calendar['provider'],
            'calendar_id' => $calendar['calendar_id'],
            'calendar_event_id' => $calendar['event_id'],
        ])->save();

        return $meeting->fresh() ?? $meeting;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(User $user, Meeting $meeting, array $payload): Meeting
    {
        $this->owned($user, $meeting);

        $updates = [];

        foreach (['title', 'meeting_type', 'timezone', 'location', 'notes'] as $field) {
            if (array_key_exists($field, $payload)) {
                $updates[$field] = $this->nullableString($payload[$field]);
            }
        }

        if (array_key_exists('started_at', $payload)) {
            $updates['started_at'] = $this->nullableDate($payload['started_at']);
        }

        if (array_key_exists('ended_at', $payload)) {
            $updates['ended_at'] = $this->nullableDate($payload['ended_at']);
        }

        if (array_key_exists('project_id', $payload)) {
            $updates['project_id'] = $this->ownedProjectId($user, $payload['project_id']);
        }

        if (array_key_exists('organization_id', $payload)) {
            $updates['organization_id'] = $this->ownedOrganizationId($user, $payload['organization_id']);
        }

        if (array_key_exists('source_external_id', $payload)) {
            $updates['source_external_id'] = $this->nullableString($payload['source_external_id']);
        }

        if ($updates !== []) {
            if (isset($updates['title']) && $updates['title'] === null) {
                unset($updates['title']);
            }

            $meeting->forceFill($updates)->save();
        }

        return $meeting->fresh(['project', 'organization', 'participants']) ?? $meeting;
    }

    public function archive(User $user, Meeting $meeting): Meeting
    {
        $this->owned($user, $meeting);
        $meeting->forceFill(['status' => MeetingStatus::Archived])->save();

        return $meeting;
    }

    public function restore(User $user, Meeting $meeting): Meeting
    {
        $this->owned($user, $meeting);
        $meeting->forceFill(['status' => MeetingStatus::Ready])->save();

        return $meeting;
    }

    public function rerunAnalysis(User $user, Meeting $meeting): Meeting
    {
        $this->owned($user, $meeting);

        $artifact = $meeting->artifacts()->latest('id')->first();

        if ($artifact === null || blank($artifact->normalized_text)) {
            throw new MeetingException('missing_transcript', 'This meeting has no transcript to analyze.');
        }

        if ($meeting->analysis_status === MeetingAnalysisStatus::Processing) {
            throw new MeetingException('already_processing', 'Analysis is already running.');
        }

        $this->dispatchAnalysis($meeting);

        return $meeting->fresh() ?? $meeting;
    }

    public function retryZoomImport(User $user, Meeting $meeting): Meeting
    {
        $this->owned($user, $meeting);

        if ($meeting->source_type !== MeetingSourceType::Zoom) {
            throw new MeetingException('not_found', 'This meeting is not a Zoom import.');
        }

        return app(ZoomMeetingIngestor::class)->retryMeeting($meeting);
    }

    public function linkParticipant(User $user, Meeting $meeting, MeetingParticipant $participant, int $personId): MeetingParticipant
    {
        $this->owned($user, $meeting);
        $this->assertParticipant($meeting, $participant);

        try {
            $person = $this->directory->ownedPerson($user, $personId);
        } catch (DirectoryException $exception) {
            throw new MeetingException('not_found', $exception->getMessage());
        }

        $linked = $this->participants->link($participant, $person);
        $this->reviews->recompose($user, $meeting->fresh() ?? $meeting);

        return $linked;
    }

    public function assignReviewSubject(User $user, Meeting $meeting, ?int $personId, bool $skip = false): Meeting
    {
        $this->owned($user, $meeting);

        return $this->reviews->assignSubject($user, $meeting, $personId, $skip);
    }

    public function applyDefaultReviewSubject(User $user, Meeting $meeting): Meeting
    {
        $this->owned($user, $meeting);

        return $this->reviews->prepareSubject($user, $meeting);
    }

    public function unlinkParticipant(User $user, Meeting $meeting, MeetingParticipant $participant): MeetingParticipant
    {
        $this->owned($user, $meeting);
        $this->assertParticipant($meeting, $participant);

        return $this->participants->unlink($participant);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createPersonFromParticipant(User $user, Meeting $meeting, MeetingParticipant $participant, array $payload = []): MeetingParticipant
    {
        $this->owned($user, $meeting);
        $this->assertParticipant($meeting, $participant);

        try {
            $person = $this->directory->createPerson($user, [
                'display_name' => $payload['display_name'] ?? $participant->display_name,
                'primary_email' => $payload['primary_email'] ?? $participant->email,
                'roles' => $payload['roles'] ?? [],
            ]);
        } catch (DirectoryException $exception) {
            throw new MeetingException($exception->error, $exception->getMessage());
        }

        return $this->participants->link($participant, $person);
    }

    public function downloadArtifact(User $user, Meeting $meeting, MeetingArtifact $artifact): StreamedResponse
    {
        $this->owned($user, $meeting);

        if ((int) $artifact->meeting_id !== (int) $meeting->id) {
            throw new MeetingException('not_found', 'Artifact not found.');
        }

        $disk = Storage::disk($artifact->disk);
        $filename = $artifact->original_filename ?: ('transcript.'.$artifact->extension);

        if (! $disk->exists($artifact->storage_path)) {
            throw new MeetingException('not_found', 'Transcript file is missing.');
        }

        return $disk->download($artifact->storage_path, $filename, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyReviewChoice(User $user, Meeting $meeting, array $payload): void
    {
        $skip = filter_var($payload['skip_leadership_review'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $personId = isset($payload['review_subject_person_id']) && $payload['review_subject_person_id'] !== ''
            ? (int) $payload['review_subject_person_id']
            : null;

        if ($skip) {
            $meeting->forceFill([
                'review_subject_person_id' => null,
                'leadership_review_status' => MeetingLeadershipReviewStatus::Skipped,
            ])->save();

            return;
        }

        if ($personId !== null && $personId > 0) {
            $this->reviews->assignSubject($user, $meeting, $personId);

            return;
        }

        $this->reviews->prepareSubject($user, $meeting);
    }

    public function owned(User $user, Meeting $meeting): Meeting
    {
        $this->assertCapability($user);

        if ((int) $meeting->user_id !== (int) $user->id) {
            throw new MeetingException('not_found', 'Meeting not found.');
        }

        return $meeting;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSummary(Meeting $meeting): array
    {
        $meeting->loadMissing(['project:id,name', 'organization:id,name', 'participants']);

        return [
            'id' => $meeting->id,
            'title' => $meeting->title,
            'started_at' => $meeting->started_at?->toIso8601String(),
            'ended_at' => $meeting->ended_at?->toIso8601String(),
            'project' => $meeting->project ? ['id' => $meeting->project->id, 'name' => $meeting->project->name] : null,
            'organization' => $meeting->organization ? ['id' => $meeting->organization->id, 'name' => $meeting->organization->name] : null,
            'source_type' => $meeting->source_type instanceof MeetingSourceType ? $meeting->source_type->value : (string) $meeting->source_type,
            'status' => $meeting->status instanceof MeetingStatus ? $meeting->status->value : (string) $meeting->status,
            'analysis_status' => $meeting->analysis_status instanceof MeetingAnalysisStatus ? $meeting->analysis_status->value : (string) $meeting->analysis_status,
            'zoom_import' => $this->zoomImportState($meeting),
            'summary' => $meeting->summary,
            'participants_count' => $meeting->participants_count ?? $meeting->participants->count(),
            'participants' => $meeting->participants->map(fn (MeetingParticipant $participant): array => [
                'id' => $participant->id,
                'display_name' => $participant->display_name,
                'person_id' => $participant->person_id,
                'resolved' => $participant->person_id !== null,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Meeting $meeting): array
    {
        $meeting->loadMissing([
            'project:id,name',
            'organization:id,name',
            'participants.person:id,display_name',
            'reviewSubject:id,display_name',
            'artifacts',
            'currentAnalysis',
            'analyses' => fn ($query) => $query->orderByDesc('version'),
        ]);

        $analysis = $meeting->currentAnalysis ?? $meeting->analyses->first();
        $artifact = $meeting->artifacts->sortByDesc('id')->first();
        $result = is_array($analysis?->result_json) ? $analysis->result_json : [];

        return [
            ...$this->serializeSummary($meeting),
            'timezone' => $meeting->timezone,
            'location' => $meeting->location,
            'meeting_type' => $meeting->meeting_type,
            'source_external_id' => $meeting->source_external_id,
            'calendar' => $meeting->calendar_event_id === null ? null : [
                'provider' => $meeting->calendar_provider,
                'calendar_id' => $meeting->calendar_id,
                'event_id' => $meeting->calendar_event_id,
            ],
            'review_subject' => $meeting->reviewSubject === null ? null : [
                'id' => $meeting->reviewSubject->id,
                'name' => $meeting->reviewSubject->display_name,
            ],
            'leadership_review_status' => $meeting->leadership_review_status instanceof MeetingLeadershipReviewStatus
                ? $meeting->leadership_review_status->value
                : $meeting->leadership_review_status,
            'source_language' => $meeting->source_language,
            'notes' => $meeting->notes,
            'project_id' => $meeting->project_id,
            'organization_id' => $meeting->organization_id,
            'current_analysis_id' => $meeting->current_analysis_id,
            'participants' => $meeting->participants->map(fn (MeetingParticipant $participant): array => [
                'id' => $participant->id,
                'display_name' => $participant->display_name,
                'email' => $participant->email,
                'role' => $participant->role,
                'speaker_key' => $participant->speaker_key,
                'person_id' => $participant->person_id,
                'person_name' => $participant->person?->display_name,
                'resolved' => $participant->person_id !== null,
            ])->values()->all(),
            'artifact' => $artifact === null ? null : [
                'id' => $artifact->id,
                'kind' => $artifact->kind instanceof MeetingArtifactKind ? $artifact->kind->value : (string) $artifact->kind,
                'original_filename' => $artifact->original_filename,
                'byte_size' => $artifact->byte_size,
                'checksum_sha256' => $artifact->checksum_sha256,
                'normalized_text' => $artifact->normalized_text,
                'original_text' => $artifact->original_text,
            ],
            'analysis' => $analysis === null ? null : $this->serializeAnalysis($analysis, $result),
            'analyses' => $meeting->analyses->map(fn (MeetingAnalysis $row): array => [
                'id' => $row->id,
                'version' => $row->version,
                'status' => $row->status instanceof MeetingAnalysisStatus ? $row->status->value : (string) $row->status,
                'provider' => $row->provider,
                'model' => $row->model,
                'processed_at' => $row->processed_at?->toIso8601String(),
                'error_class' => $row->error_class,
                'error_message' => $row->error_message,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public function serializeAnalysis(MeetingAnalysis $analysis, array $result = []): array
    {
        $result = $result !== [] ? $result : (is_array($analysis->result_json) ? $analysis->result_json : []);

        return [
            'id' => $analysis->id,
            'version' => $analysis->version,
            'status' => $analysis->status instanceof MeetingAnalysisStatus ? $analysis->status->value : (string) $analysis->status,
            'provider' => $analysis->provider,
            'model' => $analysis->model,
            'prompt_version' => $analysis->prompt_version,
            'summary' => $analysis->summary,
            'error_class' => $analysis->error_class,
            'error_message' => $analysis->error_message,
            'processed_at' => $analysis->processed_at?->toIso8601String(),
            'result' => $result,
        ];
    }

    public function storeUploadedFile(User $user, Meeting $meeting, UploadedFile $file): MeetingArtifact
    {
        $inspected = $this->inspectUpload($file);
        $existing = MeetingArtifact::query()
            ->where('meeting_id', $meeting->id)
            ->where('checksum_sha256', $inspected['checksum'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $path = $this->storeBytes($user, $meeting, $inspected['contents'], $inspected['extension']);
        $normalized = $this->normalizer->normalize($inspected['contents'], $inspected['extension']);

        $artifact = MeetingArtifact::query()->create([
            'meeting_id' => $meeting->id,
            'kind' => MeetingArtifactKind::OriginalFile,
            'original_filename' => $inspected['filename'],
            'mime_type' => $inspected['mime'],
            'extension' => $inspected['extension'],
            'checksum_sha256' => $inspected['checksum'],
            'byte_size' => $inspected['size'],
            'disk' => MeetingConfig::disk(),
            'storage_path' => $path,
            'original_text' => $inspected['contents'],
            'normalized_text' => $normalized['text'],
        ]);

        $this->participants->seedFromTranscript($user, $meeting, $normalized['speakers']);

        return $artifact;
    }

    public function storePastedText(User $user, Meeting $meeting, string $text): MeetingArtifact
    {
        return $this->storeImportedText(
            $user,
            $meeting,
            $text,
            'txt',
            'pasted-transcript.txt',
            MeetingArtifactKind::OriginalText,
        );
    }

    public function storeImportedText(
        User $user,
        Meeting $meeting,
        string $text,
        string $extension = 'txt',
        string $filename = 'transcript.txt',
        MeetingArtifactKind $kind = MeetingArtifactKind::OriginalText,
    ): MeetingArtifact {
        $this->owned($user, $meeting);
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $extension = strtolower($extension);

        if (trim($text) === '') {
            throw new MeetingException('empty_transcript', 'Transcript is empty.');
        }

        if (mb_strlen($text) > MeetingConfig::maxPasteChars() || strlen($text) > MeetingConfig::maxFileSizeBytes()) {
            throw new MeetingException('file_too_large', 'Transcript is too large.');
        }

        if (! in_array($extension, MeetingConfig::allowedExtensions(), true)) {
            throw new MeetingException('unsupported_format', 'Supported formats: txt, vtt, srt, md.');
        }

        $checksum = hash('sha256', $text);
        $existing = MeetingArtifact::query()
            ->where('meeting_id', $meeting->id)
            ->where('checksum_sha256', $checksum)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $path = $this->storeBytes($user, $meeting, $text, $extension);
        $normalized = $this->normalizer->normalize($text, $extension);
        $mime = match ($extension) {
            'vtt' => 'text/vtt',
            'srt' => 'application/x-subrip',
            'md' => 'text/markdown',
            default => 'text/plain',
        };

        $artifact = MeetingArtifact::query()->create([
            'meeting_id' => $meeting->id,
            'kind' => $kind,
            'original_filename' => $filename,
            'mime_type' => $mime,
            'extension' => $extension,
            'checksum_sha256' => $checksum,
            'byte_size' => strlen($text),
            'disk' => MeetingConfig::disk(),
            'storage_path' => $path,
            'original_text' => $text,
            'normalized_text' => $normalized['text'],
        ]);

        $this->participants->seedFromTranscript($user, $meeting, $normalized['speakers']);

        return $artifact;
    }

    public function dispatchAnalysis(Meeting $meeting): void
    {
        $meeting->forceFill([
            'analysis_status' => MeetingAnalysisStatus::Pending,
        ])->save();

        AnalyzeMeetingTranscriptJob::dispatch($meeting->id, (int) $meeting->user_id);
    }

    /**
     * @return array{status: string|null, error: string|null, retryable: bool}
     */
    public function zoomImportState(Meeting $meeting): array
    {
        $metadata = is_array($meeting->metadata) ? $meeting->metadata : [];
        $zoom = is_array($metadata['zoom'] ?? null) ? $metadata['zoom'] : [];
        $status = isset($zoom['import_status']) ? (string) $zoom['import_status'] : null;
        $error = isset($zoom['last_error']) ? (string) $zoom['last_error'] : null;
        $retryable = in_array($status, ['failed', 'transcript_unavailable', 'blocked_auth'], true);

        if ($meeting->source_type !== MeetingSourceType::Zoom) {
            return [
                'status' => null,
                'error' => null,
                'retryable' => false,
            ];
        }

        return [
            'status' => $status,
            'error' => $error !== '' ? $error : null,
            'retryable' => $retryable,
        ];
    }

    /**
     * @return array{filename: string, extension: string, mime: string, checksum: string, size: int, contents: string}
     */
    private function inspectUpload(UploadedFile $file): array
    {
        $filename = basename((string) $file->getClientOriginalName());
        $filename = str_replace(["\0", '/', '\\'], '', $filename);

        if ($filename === '' || str_contains($filename, '..')) {
            throw new MeetingException('unsupported_format', 'Invalid filename.');
        }

        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));

        if (! in_array($extension, MeetingConfig::allowedExtensions(), true)) {
            throw new MeetingException('unsupported_format', 'Supported formats: txt, vtt, srt, md.');
        }

        $size = (int) $file->getSize();

        if ($size < 1) {
            throw new MeetingException('empty_transcript', 'The file is empty.');
        }

        if ($size > MeetingConfig::maxFileSizeBytes()) {
            throw new MeetingException('file_too_large', 'Transcript files must be '.MeetingConfig::maxFileSizeMb().' MB or smaller.');
        }

        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));

        if ($mime !== '' && ! in_array($mime, MeetingConfig::allowedMimeTypes(), true)) {
            throw new MeetingException('unsupported_format', 'That file type is not allowed.');
        }

        $contents = (string) file_get_contents($file->getRealPath() ?: $file->getPathname());

        if (str_starts_with(ltrim($contents), '<?') || str_starts_with($contents, "\x7fELF")) {
            throw new MeetingException('unsupported_format', 'Executable content is not allowed.');
        }

        return [
            'filename' => $filename,
            'extension' => $extension,
            'mime' => $mime !== '' ? $mime : 'text/plain',
            'checksum' => hash('sha256', $contents),
            'size' => strlen($contents),
            'contents' => $contents,
        ];
    }

    private function storeBytes(User $user, Meeting $meeting, string $contents, string $extension): string
    {
        $path = MeetingConfig::directory().'/'.$user->id.'/'.$meeting->id.'/'.Str::uuid()->toString().'.'.$extension;
        Storage::disk(MeetingConfig::disk())->put($path, $contents);

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function titleFrom(array $payload, ?UploadedFile $file, string $paste): string
    {
        $title = $this->nullableString($payload['title'] ?? null);

        if ($title !== null) {
            return mb_substr($title, 0, 190);
        }

        if ($file !== null) {
            $name = pathinfo((string) $file->getClientOriginalName(), PATHINFO_FILENAME);

            return mb_substr($name !== '' ? $name : 'Meeting', 0, 190);
        }

        $first = strtok($paste, "\n") ?: 'Meeting';

        return mb_substr(trim($first), 0, 190) ?: 'Meeting';
    }

    private function ownedProjectId(User $user, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        if ($id < 1) {
            return null;
        }

        $exists = Project::query()->where('user_id', $user->id)->whereKey($id)->exists();

        if (! $exists) {
            throw new MeetingException('invalid_project', 'Project not found.');
        }

        return $id;
    }

    private function ownedOrganizationId(User $user, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $id = (int) $value;

        if ($id < 1) {
            return null;
        }

        $exists = Organization::query()->where('user_id', $user->id)->whereKey($id)->exists();

        if (! $exists) {
            throw new MeetingException('invalid_organization', 'Organization not found.');
        }

        return $id;
    }

    private function assertParticipant(Meeting $meeting, MeetingParticipant $participant): void
    {
        if ((int) $participant->meeting_id !== (int) $meeting->id) {
            throw new MeetingException('not_found', 'Participant not found.');
        }
    }

    private function assertCapability(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::MEETINGS)) {
            throw new MeetingException('capability_denied', 'Meetings are not available.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 5000);
    }

    private function plannedMoment(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value), $timezone)->utc();
        } catch (Throwable) {
            throw new MeetingException('invalid_date', 'Use a valid date and time.');
        }
    }

    /**
     * Calendar reference is all three parts or nothing, same rule as tasks.
     *
     * @param  array<string, mixed>  $payload
     * @return array{provider: string|null, calendar_id: string|null, event_id: string|null}
     */
    private function calendarReference(array $payload): array
    {
        $provider = $this->nullableString($payload['calendar_provider'] ?? null);
        $calendarId = $this->nullableString($payload['calendar_id'] ?? null);
        $eventId = $this->nullableString($payload['calendar_event_id'] ?? null);
        $filled = array_filter([$provider, $calendarId, $eventId], static fn (?string $part): bool => $part !== null);

        if ($filled !== [] && count($filled) !== 3) {
            throw new MeetingException('invalid_calendar_reference', 'Provide provider, calendar id and event id together.');
        }

        return [
            'provider' => $provider,
            'calendar_id' => $calendarId,
            'event_id' => $eventId,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{display_name?: string, email?: string|null, speaker_key?: string|null}>
     */
    private function plannedParticipants(array $payload): array
    {
        $raw = $payload['participants'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        $rows = [];

        foreach ($raw as $item) {
            if (is_string($item)) {
                $item = ['display_name' => $item];
            }

            if (! is_array($item)) {
                continue;
            }

            $name = $this->nullableString($item['display_name'] ?? null);
            $email = $this->nullableString($item['email'] ?? null);

            if ($name === null && $email === null) {
                continue;
            }

            $rows[] = [
                'display_name' => $name ?? (string) $email,
                'email' => $email,
            ];
        }

        return $rows;
    }

    private function nullableDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
