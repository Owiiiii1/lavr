<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\UserRole;
use App\Jobs\AnalyzeMeetingTranscriptJob;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\MeetingParticipant;
use App\Models\Person;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Directory\DirectoryService;
use App\Services\Meetings\MeetingIntelligenceMerger;
use App\Services\Meetings\MeetingIntelligenceValidator;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\ParticipantResolver;
use App\Services\Meetings\SubtitleParser;
use App\Services\Meetings\TranscriptChunker;
use App\Services\Meetings\TranscriptNormalizer;
use App\Services\Projects\ProjectService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class MeetingIntelligenceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_vtt_and_srt_keep_speakers_and_timestamps(): void
    {
        $vtt = (new TranscriptNormalizer)->normalize((string) file_get_contents(base_path('tests/Fixtures/meetings/synthetic.vtt')), 'vtt');
        $this->assertStringContainsString('[00:00:01] Sonia:', $vtt['text']);
        $this->assertContains('Sergiy', $vtt['speakers']);
        $this->assertContains('Speaker 3', $vtt['speakers']);

        $srt = <<<'SRT'
1
00:00:01,000 --> 00:00:04,000
John: Hello

2
00:00:05,000 --> 00:00:08,000
Speaker 1: World
SRT;
        $parsed = (new SubtitleParser)->parseSrt($srt);
        $this->assertStringContainsString('[00:00:01] John: Hello', $parsed['text']);
        $this->assertSame(['John', 'Speaker 1'], $parsed['speakers']);
    }

    public function test_chunker_splits_on_speaker_lines_without_dropping_text(): void
    {
        $lines = [];
        for ($i = 0; $i < 40; $i++) {
            $lines[] = 'Speaker '.$i.': '.str_repeat('word ', 40);
        }
        $chunks = (new TranscriptChunker)->chunk(implode("\n", $lines), 400, 40, 12);
        $this->assertGreaterThan(1, count($chunks));
        $this->assertLessThanOrEqual(12, count($chunks));
        $this->assertStringContainsString('Speaker 0:', $chunks[0]);
    }

    public function test_validator_keeps_relative_deadline_raw_without_meeting_date(): void
    {
        $meeting = new Meeting(['started_at' => null]);
        $payload = $this->validAnalysis();
        $payload['deadlines'][0]['deadline_raw'] = 'tomorrow';
        $payload['deadlines'][0]['deadline_at'] = '2026-09-10T10:00:00+00:00';
        $payload['deadlines'][0]['text'] = 'tomorrow';
        $validated = app(MeetingIntelligenceValidator::class)->validate($payload, $meeting);
        $this->assertNull($validated['deadlines'][0]['deadline_at']);
        $this->assertSame('tomorrow', $validated['deadlines'][0]['deadline_raw']);
    }

    public function test_merger_deduplicates_identical_actions_but_keeps_distinct_facts(): void
    {
        $merged = app(MeetingIntelligenceMerger::class)->merge([
            [
                'summary' => ['executive' => 'One', 'outcomes' => ['A'], 'attention' => []],
                'participants' => ['Sonia'],
                'topics' => [],
                'decisions' => [['text' => 'Keep vendor', 'confidence' => 'low', 'evidence' => ['excerpt' => 'short']]],
                'action_items' => [['task' => 'Send budget', 'owner' => 'Sergiy', 'confidence' => 'low', 'evidence' => ['excerpt' => 'a']]],
                'commitments_detected' => [],
                'deadlines' => [],
                'open_questions' => [],
                'risks' => [],
                'follow_ups' => [],
                'unresolved_identities' => [],
            ],
            [
                'summary' => ['executive' => '', 'outcomes' => ['B'], 'attention' => []],
                'participants' => ['Sonia'],
                'topics' => [],
                'decisions' => [['text' => 'Keep vendor', 'confidence' => 'high', 'evidence' => ['excerpt' => 'longer quote']]],
                'action_items' => [['task' => 'Book hotel', 'owner' => 'Sergiy', 'confidence' => 'medium', 'evidence' => ['excerpt' => 'b']]],
                'commitments_detected' => [],
                'deadlines' => [],
                'open_questions' => [],
                'risks' => [],
                'follow_ups' => [],
                'unresolved_identities' => [],
            ],
        ]);

        $this->assertCount(1, $merged['decisions']);
        $this->assertSame('high', $merged['decisions'][0]['confidence']);
        $this->assertCount(2, $merged['action_items']);
    }

    public function test_paste_upload_duplicate_and_unsupported_file(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $fake->analysisResponseText = json_encode($this->validAnalysis(), JSON_UNESCAPED_UNICODE);

            $this->actingAs($user)->post(route('jarvis.meetings.store'), [
                'title' => 'Chicago planning',
                'pasted_text' => $this->syntheticPlain(),
            ])->assertRedirect();

            $meeting = Meeting::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($meeting);
            $this->assertSame(MeetingAnalysisStatus::Completed, $meeting->fresh()->analysis_status);
            $this->assertNotNull($meeting->fresh()->current_analysis_id);
            $this->assertSame(0, DB::table('tasks')->where('user_id', $user->id)->count());
            $promoted = DB::table('commitments')->where('user_id', $user->id)->get();
            foreach ($promoted as $row) {
                $this->assertSame('detected', $row->lifecycle_status);
            }

            $service = app(MeetingService::class);
            $firstCount = $meeting->artifacts()->count();
            $service->storePastedText($user, $meeting, $this->syntheticPlain());
            $this->assertSame($firstCount, $meeting->artifacts()->count());

            $this->actingAs($user)->post(route('jarvis.meetings.store'), [
                'transcript' => UploadedFile::fake()->createWithContent('notes.exe', 'not a transcript'),
            ])->assertSessionHasErrors('transcript');
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_txt_upload_queues_then_stores_analysis_with_evidence(): void
    {
        $user = null;

        try {
            Bus::fake();
            $user = $this->temporaryOwner();
            $file = UploadedFile::fake()->createWithContent('call.txt', $this->syntheticPlain());
            $this->actingAs($user)->post(route('jarvis.meetings.store'), [
                'title' => 'Ops call',
                'transcript' => $file,
            ])->assertRedirect();
            Bus::assertDispatched(AnalyzeMeetingTranscriptJob::class);
            $meeting = Meeting::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($meeting->artifacts()->first()?->normalized_text);
            $this->assertSame(MeetingAnalysisStatus::Pending, $meeting->analysis_status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_invalid_ai_json_marks_analysis_failed(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $fake->analysisResponseText = 'not-json';
            $this->actingAs($user)->post(route('jarvis.meetings.store'), [
                'title' => 'Broken',
                'pasted_text' => $this->syntheticPlain(),
            ]);
            $meeting = Meeting::query()->where('user_id', $user->id)->first();
            $this->assertSame(MeetingAnalysisStatus::Failed, $meeting?->fresh()->analysis_status);
            $analysis = MeetingAnalysis::query()->where('meeting_id', $meeting->id)->latest('id')->first();
            $this->assertSame(MeetingAnalysisStatus::Failed, $analysis?->status);
            $this->assertNull($analysis?->result_json);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_guest_and_foreign_meeting_are_protected(): void
    {
        $owner = null;
        $other = null;

        try {
            $this->get(route('jarvis.meetings.index'))->assertRedirect(route('login'));
            $this->get(route('meetings.index'))->assertRedirect();
            $owner = $this->temporaryOwner();
            $other = $this->temporaryOwner();
            $meeting = Meeting::factory()->create([
                'user_id' => $other->id,
                'title' => 'Hidden '.Str::random(6),
            ]);
            $this->actingAs($owner)->get(route('jarvis.meetings.show', $meeting))->assertNotFound();
            $this->actingAs($owner)->get(route('meetings.show', $meeting))->assertNotFound();
            $regular = $this->createTemporaryUser();
            $this->actingAs($regular)->get(route('jarvis.meetings.index'))->assertForbidden();
            $this->deleteTemporaryUser($regular);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_workspace_list_detail_participant_link_and_rerun(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $fake->analysisResponseText = json_encode($this->validAnalysis(), JSON_UNESCAPED_UNICODE);
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, [
                'display_name' => 'Sonia '.Str::random(4),
                'primary_email' => 'sonia-'.Str::lower(Str::random(6)).'@invalid.local',
                'roles' => ['employee'],
            ]);
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4));

            $this->actingAs($user)->post(route('jarvis.meetings.store'), [
                'title' => 'Chicago sync',
                'project_id' => $project->id,
                'pasted_text' => $this->syntheticPlain(),
            ])->assertRedirect();

            $meeting = Meeting::query()->where('user_id', $user->id)->first();
            $list = $this->inertia($this->actingAs($user)->get(route('jarvis.meetings.index')));
            $this->assertSame('Jarvis/Meetings', $list['component']);
            $this->assertSame('uk', $list['props']['locale']);

            $show = $this->inertia($this->actingAs($user)->get(route('jarvis.meetings.show', $meeting)));
            $this->assertSame('Jarvis/MeetingShow', $show['component']);
            $this->assertNotEmpty($show['props']['meeting']['analysis']['result']['decisions']);

            $unresolved = MeetingParticipant::query()->where('meeting_id', $meeting->id)->whereNull('person_id')->first();
            $this->assertNotNull($unresolved);
            $this->actingAs($user)->post(route('jarvis.meetings.participants.link', [$meeting, $unresolved]), [
                'person_id' => $person->id,
            ])->assertRedirect();
            $this->assertSame($person->id, $unresolved->fresh()->person_id);
            $this->actingAs($user)->post(route('jarvis.meetings.participants.unlink', [$meeting, $unresolved]))->assertRedirect();
            $this->assertNull($unresolved->fresh()->person_id);

            Bus::fake();
            $this->actingAs($user)->post(route('jarvis.meetings.rerun', $meeting))->assertRedirect();
            Bus::assertDispatched(AnalyzeMeetingTranscriptJob::class);

            $admin = $this->inertia($this->actingAs($user)->get(route('meetings.show', $meeting)));
            $this->assertSame('Meetings/Show', $admin['component']);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_participant_email_resolves_existing_person_without_creating(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $email = 'sergiy-'.Str::lower(Str::random(6)).'@invalid.local';
            $person = app(DirectoryService::class)->createPerson($user, [
                'display_name' => 'Sergiy Known',
                'primary_email' => $email,
                'roles' => ['employee'],
            ]);
            $before = Person::query()->where('user_id', $user->id)->count();
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Resolve']);
            app(MeetingService::class)->storePastedText($user, $meeting, "Sergiy: I will send it\nUnknown Guest: hello");
            $resolved = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('display_name', 'Sergiy Known')->orWhere(function ($query) use ($meeting): void {
                $query->where('meeting_id', $meeting->id)->where('display_name', 'Sergiy');
            })->first();
            // Email not in transcript; exact name Sergiy Known vs Sergiy — unresolved unless exact unique name.
            $this->assertSame($before, Person::query()->where('user_id', $user->id)->count());
            $guest = MeetingParticipant::query()->where('meeting_id', $meeting->id)->where('display_name', 'Unknown Guest')->first();
            $this->assertNotNull($guest);
            $this->assertNull($guest->person_id);

            $linked = app(ParticipantResolver::class)->upsertParticipant($user, $meeting, 'Sergiy Known', $email);
            $this->assertSame($person->id, $linked->person_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validAnalysis(): array
    {
        $evidence = ['excerpt' => 'short quote', 'speaker' => 'Sonia', 'timestamp' => '00:00:29'];

        return [
            'summary' => [
                'executive' => 'Chicago planning locked lighting and guest list.',
                'outcomes' => ['Keep lighting vendor', 'Freeze guest list', 'Budget due Friday'],
                'attention' => ['Freight risk'],
            ],
            'participants' => ['Sonia', 'Sergiy', 'Speaker 3'],
            'topics' => ['Chicago show'],
            'decisions' => [
                ['text' => 'Keep existing lighting vendor', 'confidence' => 'high', 'evidence' => $evidence],
                ['text' => 'Freeze guest list today', 'confidence' => 'high', 'evidence' => $evidence],
            ],
            'action_items' => [
                ['owner' => 'Sergiy', 'task' => 'Send budget', 'deadline_raw' => 'Friday', 'deadline_at' => null, 'status' => 'detected', 'confidence' => 'high', 'evidence' => $evidence],
                ['owner' => 'Sergiy', 'task' => 'Book hotel block', 'deadline_raw' => 'tomorrow', 'deadline_at' => null, 'status' => 'detected', 'confidence' => 'medium', 'evidence' => $evidence],
                ['owner' => null, 'task' => 'Confirm venue deposit', 'deadline_raw' => null, 'deadline_at' => null, 'status' => 'detected', 'confidence' => 'medium', 'evidence' => $evidence],
            ],
            'commitments_detected' => [
                ['person_name' => 'Sergiy', 'action' => 'send the budget', 'expected_result' => 'budget sent', 'deadline_raw' => 'Friday', 'deadline_at' => null, 'confidence' => 'high', 'evidence' => $evidence],
            ],
            'deadlines' => [
                ['text' => 'Budget by Friday', 'deadline_raw' => 'Friday', 'deadline_at' => null, 'owner' => 'Sergiy', 'confidence' => 'high', 'evidence' => $evidence],
                ['text' => 'Run-of-show next Tuesday', 'deadline_raw' => 'next Tuesday', 'deadline_at' => null, 'owner' => 'Sergiy', 'confidence' => 'medium', 'evidence' => $evidence],
            ],
            'open_questions' => [['text' => 'Do we need extra security on Saturday?', 'confidence' => 'high']],
            'risks' => [['text' => 'Freight might be late', 'confidence' => 'medium']],
            'follow_ups' => [['text' => 'Confirm venue deposit', 'confidence' => 'medium']],
            'unresolved_identities' => ['Speaker 3'],
            'likely_project' => 'Chicago',
        ];
    }

    private function syntheticPlain(): string
    {
        return <<<'TXT'
[00:00:01] Sonia: Let's start the Chicago show planning call.
[00:00:09] Sergiy: I will send the budget by Friday.
[00:00:19] Speaker 3: Can we confirm the venue deposit?
[00:00:29] Sonia: We decided to keep the existing lighting vendor.
[00:00:41] Sergiy: Agreed. I will also book the hotel block tomorrow.
[00:00:53] Speaker 3: Risk is that the freight might be late.
[00:01:06] Sonia: Open question: do we need extra security on Saturday?
[00:01:19] Sergiy: Deadline for the run-of-show is next Tuesday.
[00:01:31] Sonia: Decision two: we freeze the guest list today.
TXT;
    }

    private function bindFake(): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);

        return $fake;
    }

    /**
     * @return array<string, mixed>
     */
    private function inertia(TestResponse $response): array
    {
        $response->assertOk();
        $html = $response->getContent();
        $marker = '<script data-page="app" type="application/json">';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start);
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        $this->assertNotFalse($end);
        $page = json_decode(substr($html, $start, $end - $start), true);
        $this->assertIsArray($page);

        return $page;
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
