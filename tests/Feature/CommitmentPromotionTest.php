<?php

namespace Tests\Feature;

use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\UserRole;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Person;
use App\Models\User;
use App\Services\Commitments\CommitmentPromotionService;
use App\Services\Commitments\CommitmentService;
use App\Services\Projects\ProjectNameNormalizer;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class CommitmentPromotionTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_high_confidence_resolved_person_becomes_detected_not_open(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Sergiy');
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Chicago '.Str::random(4)]);
            $analysis = $this->analysis($meeting, [[
                'person_name' => 'Sergiy',
                'action' => 'Send final budget by Friday',
                'expected_result' => 'Final Chicago budget',
                'deadline_raw' => 'Friday',
                'confidence' => 'high',
                'evidence' => ['excerpt' => 'I will send the budget Friday', 'speaker' => 'Sergiy'],
            ]]);

            $outcome = app(CommitmentPromotionService::class)->promoteFromMeetingAnalysis($user, $meeting, $analysis, true);
            $this->assertCount(1, $outcome['created']);
            $commitment = $outcome['created'][0];
            $this->assertSame(CommitmentLifecycleStatus::Detected, $commitment->lifecycle_status);
            $this->assertSame(CommitmentEffectiveStatus::Detected, $commitment->status);
            $this->assertSame($person->id, $commitment->person_id);
            $this->assertSame(CommitmentConfidence::High, $commitment->confidence);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_unresolved_person_is_marked_and_medium_is_not_auto_promoted(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Planning '.Str::random(4)]);
            $analysis = $this->analysis($meeting, [
                [
                    'person_name' => 'Unknown Speaker',
                    'action' => 'Send lighting list',
                    'confidence' => 'high',
                    'evidence' => ['excerpt' => 'I will send the lighting list'],
                ],
                [
                    'person_name' => 'Sergiy',
                    'action' => 'Maybe update the deck',
                    'confidence' => 'medium',
                    'evidence' => ['excerpt' => 'maybe the deck'],
                ],
            ]);

            $outcome = app(CommitmentPromotionService::class)->promoteFromMeetingAnalysis($user, $meeting, $analysis, true);
            $this->assertCount(1, $outcome['created']);
            $this->assertTrue($outcome['created'][0]->unresolved_person);
            $this->assertNull($outcome['created'][0]->person_id);
            $this->assertNotSame(CommitmentEffectiveStatus::Open, $outcome['created'][0]->status);
            $this->assertGreaterThanOrEqual(1, count($outcome['skipped']));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_reanalysis_does_not_duplicate_and_preserves_owner_edits(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->person($user, 'Sergiy');
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'title' => 'Chicago '.Str::random(4)]);
            $first = $this->analysis($meeting, [[
                'person_name' => 'Sergiy',
                'action' => 'Send final budget',
                'expected_result' => 'Budget file',
                'deadline_raw' => 'Friday',
                'confidence' => 'high',
                'evidence' => ['excerpt' => 'send budget Friday'],
            ]]);
            $promotion = app(CommitmentPromotionService::class);
            $created = $promotion->promoteFromMeetingAnalysis($user, $meeting, $first, true)['created'][0];
            $service = app(CommitmentService::class);
            $open = $service->confirmDetected($user, $created, ['title' => 'Send Chicago budget v2']);
            $this->assertSame('Send Chicago budget v2', $open->title);

            $second = $this->analysis($meeting, [[
                'person_name' => 'Sergiy',
                'action' => 'Send final budget',
                'expected_result' => 'Budget file',
                'deadline_raw' => 'Friday',
                'confidence' => 'high',
                'evidence' => ['excerpt' => 'budget still Friday, slightly reworded'],
            ]], 2);
            $again = $promotion->promoteFromMeetingAnalysis($user, $meeting, $second, true);
            $this->assertCount(0, $again['created']);
            $this->assertCount(1, $again['updated']);
            $this->assertSame($open->id, $again['updated'][0]->id);
            $this->assertSame('Send Chicago budget v2', $again['updated'][0]->title);
            $this->assertGreaterThan(1, $open->fresh()->evidence()->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function analysis(Meeting $meeting, array $items, int $version = 1): MeetingAnalysis
    {
        $analysis = MeetingAnalysis::query()->create([
            'meeting_id' => $meeting->id,
            'version' => $version,
            'status' => MeetingAnalysisStatus::Completed,
            'result_json' => [
                'summary' => ['executive' => 'ok', 'outcomes' => ['ok'], 'attention' => []],
                'commitments_detected' => $items,
            ],
        ]);
        $meeting->forceFill([
            'current_analysis_id' => $analysis->id,
            'analysis_status' => MeetingAnalysisStatus::Completed,
        ])->save();

        return $analysis;
    }

    private function person(User $user, string $name): Person
    {
        return Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'normalized_name' => ProjectNameNormalizer::normalize($name),
        ]);
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
