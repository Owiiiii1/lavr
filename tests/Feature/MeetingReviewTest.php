<?php

namespace Tests\Feature;

use App\Enums\MeetingAnalysisStatus;
use App\Enums\MeetingLeadershipReviewStatus;
use App\Enums\UserRole;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\MeetingParticipant;
use App\Models\Person;
use App\Models\User;
use App\Services\Meetings\MeetingReviewComposer;
use App\Services\Meetings\MeetingReviewService;
use App\Services\Productivity\ProductivitySettingsService;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class MeetingReviewTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_global_default_review_person_is_applied_and_foreign_person_is_ignored(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->owner();
            $other = $this->owner();
            $person = Person::factory()->create(['user_id' => $user->id, 'display_name' => 'Mira Chen']);
            $foreign = Person::factory()->create(['user_id' => $other->id, 'display_name' => 'Other Person']);
            $settings = app(ProductivitySettingsService::class);
            $settings->update($user, [
                'default_review_person_id' => $foreign->id,
                'auto_generate_leadership_review' => true,
            ]);
            $this->assertNull($settings->for($user->fresh())->default_review_person_id);

            $settings->update($user, [
                'default_review_person_id' => $person->id,
                'auto_generate_leadership_review' => true,
            ]);
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Default '.Str::random(4)]);
            app(MeetingReviewService::class)->prepareSubject($user, $meeting);

            $this->assertSame($person->id, $meeting->fresh()->review_subject_person_id);
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_per_meeting_override_recomposes_without_a_new_transcript(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $mira = Person::factory()->create(['user_id' => $user->id, 'display_name' => 'Mira Chen']);
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Override '.Str::random(4)]);
            MeetingParticipant::query()->create([
                'meeting_id' => $meeting->id,
                'display_name' => 'Mira Chen',
                'person_id' => $mira->id,
            ]);
            $analysis = MeetingAnalysis::query()->create([
                'meeting_id' => $meeting->id,
                'version' => 1,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => $this->deadlineExtraction(),
                'prompt_version' => 'meeting-intelligence-v1',
            ]);
            $meeting->forceFill(['current_analysis_id' => $analysis->id, 'analysis_status' => MeetingAnalysisStatus::Completed])->save();

            $this->actingAs($user)
                ->post(route('meetings.review-subject', $meeting), ['review_subject_person_id' => $mira->id])
                ->assertRedirect();

            $fresh = $meeting->fresh();
            $this->assertSame($mira->id, $fresh->review_subject_person_id);
            $this->assertSame(MeetingLeadershipReviewStatus::Completed, $fresh->leadership_review_status);
            $review = $fresh->currentAnalysis->result_json['review'];
            $this->assertSame(2, $review['schema_version']);
            $this->assertSame($analysis->id, $fresh->current_analysis_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_unmatched_default_person_stays_pending_and_keeps_meeting_facts(): void
    {
        $composer = new MeetingReviewComposer;
        $result = $composer->compose(
            $this->deadlineExtraction(),
            ['id' => 9, 'name' => 'Mira Chen'],
            false,
            true,
        );

        $this->assertSame('pending_subject', $result['review']['leadership_status']);
        $this->assertNotSame('', $result['review']['main_insight']);
        $this->assertSame([], $result['review']['improvements']);
    }

    public function test_later_decision_removes_the_earlier_hypothesis_from_the_executive_report(): void
    {
        $result = (new MeetingReviewComposer)->compose($this->temporalExtraction(), null, false, false);

        $risks = array_column($result['review']['risks'], 'text');
        $questions = array_column($result['review']['open_questions'], 'text');
        $decisions = array_column($result['review']['decisions'], 'text');

        $this->assertNotContains('Automatic Zoom connection might not work', $risks);
        $this->assertNotContains('Will the freight arrive on time', $questions);
        $this->assertContains('We decided automatic Zoom integration will proceed', $decisions);
        $this->assertContains('Venue deposit is still unpaid', $risks);
    }

    public function test_matching_commitment_is_not_a_second_operational_item(): void
    {
        $result = (new MeetingReviewComposer)->compose([
            'action_items' => [[
                'owner' => 'Alex Rivera',
                'task' => 'Send the budget by Friday',
                'deadline_raw' => 'Friday',
            ]],
            'commitments_detected' => [[
                'person_name' => 'Alex Rivera',
                'action' => 'Send the budget by Friday',
                'confidence' => 'high',
            ]],
            'decisions' => [],
            'open_questions' => [],
            'risks' => [],
            'follow_ups' => [],
        ], null, false, false);

        $this->assertCount(1, $result['review']['actions']);
        $this->assertSame(0, $result['review']['commitments']['detected']);
        $this->assertTrue($result['commitments_detected'][0]['duplicate_of_action']);
    }

    public function test_subject_deadline_gap_is_reported_without_a_score_or_personality_label(): void
    {
        $result = (new MeetingReviewComposer)->compose(
            $this->deadlineExtraction(),
            ['id' => 3, 'name' => 'Mira Chen'],
            true,
            true,
        );
        $encoded = json_encode($result['review'], JSON_UNESCAPED_UNICODE);

        $this->assertSame('needs_attention', $this->indicator($result, 'deadline_clarity'));
        $this->assertStringContainsString('4 of 5', (string) $encoded);
        $this->assertArrayNotHasKey('score', $result['review']);
        $this->assertArrayNotHasKey('rating', $result['review']);
        $this->assertStringNotContainsString('lazy', mb_strtolower((string) $encoded));
        $this->assertStringNotContainsString('toxic', mb_strtolower((string) $encoded));
        $this->assertLessThanOrEqual(3, count($result['review']['recommendations']));
    }

    public function test_another_persons_vague_action_is_not_blamed_on_the_review_subject(): void
    {
        $result = (new MeetingReviewComposer)->compose([
            'action_items' => [[
                'owner' => 'Alex Rivera',
                'task' => 'check the booth layout later',
                'evidence' => ['speaker' => 'Alex Rivera', 'excerpt' => 'I will check the booth layout later'],
            ]],
            'commitments_detected' => [],
            'decisions' => [],
            'open_questions' => [],
            'risks' => [],
            'follow_ups' => [],
        ], ['id' => 3, 'name' => 'Mira Chen'], true, true);

        $this->assertSame('insufficient_data', $this->indicator($result, 'deadline_clarity'));
        $this->assertSame([], $result['review']['improvements']);
        $blob = mb_strtolower(json_encode($result['review']['improvements']));
        $this->assertStringNotContainsString('booth', $blob);
    }

    public function test_mobile_review_layout_keeps_wrapping_metrics_and_stacked_actions(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/MeetingReview.jsx'));

        $this->assertStringContainsString('grid-cols-2', $source);
        $this->assertStringContainsString('md:hidden', $source);
        $this->assertStringContainsString('hidden md:block', $source);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function indicator(array $result, string $key): string
    {
        foreach ($result['review']['indicators'] as $indicator) {
            if ($indicator['key'] === $key) {
                return $indicator['status'];
            }
        }

        $this->fail('Missing indicator '.$key);
    }

    /**
     * @return array<string, mixed>
     */
    private function deadlineExtraction(): array
    {
        $actions = [];

        for ($index = 1; $index <= 5; $index++) {
            $actions[] = [
                'owner' => 'Mira Chen',
                'task' => 'Prepare venue packet number '.$index.' for the crew',
                'deadline_raw' => $index === 1 ? 'Friday' : null,
                'evidence' => ['speaker' => 'Mira Chen', 'excerpt' => 'I will prepare venue packet number '.$index],
            ];
        }

        return [
            'action_items' => $actions,
            'commitments_detected' => [],
            'decisions' => [['text' => 'Keep the current lighting vendor']],
            'open_questions' => [],
            'risks' => [],
            'follow_ups' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function temporalExtraction(): array
    {
        return [
            'action_items' => [],
            'commitments_detected' => [],
            'decisions' => [
                [
                    'text' => 'We decided automatic Zoom integration will proceed',
                    'evidence' => ['timestamp' => '10:15', 'excerpt' => 'Then we do automatic Zoom integration'],
                ],
                [
                    'text' => 'The freight will arrive on Friday',
                    'evidence' => ['timestamp' => '10:20', 'excerpt' => 'Freight will arrive on Friday'],
                ],
            ],
            'open_questions' => [
                [
                    'text' => 'Will the freight arrive on time',
                    'kind' => 'question',
                    'evidence' => ['timestamp' => '10:05', 'excerpt' => 'Will the freight arrive on time?'],
                ],
                [
                    'text' => 'Maybe we brainstorm extra stage colors',
                    'kind' => 'brainstorm',
                    'evidence' => ['timestamp' => '10:02'],
                ],
            ],
            'risks' => [
                [
                    'text' => 'Automatic Zoom connection might not work',
                    'severity' => 'medium',
                    'evidence' => ['timestamp' => '10:00', 'excerpt' => 'Automatic Zoom connection might not work'],
                ],
                [
                    'text' => 'Venue deposit is still unpaid',
                    'severity' => 'high',
                    'evidence' => ['timestamp' => '10:30', 'excerpt' => 'Venue deposit is still unpaid'],
                ],
            ],
            'follow_ups' => [],
        ];
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
