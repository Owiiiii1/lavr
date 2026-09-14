<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\LeadershipFindingCategory;
use App\Enums\LeadershipFindingSeverity;
use App\Enums\LeadershipReviewStatus;
use App\Enums\LeadershipReviewType;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\OwnerLocale;
use App\Enums\UserRole;
use App\Models\Commitment;
use App\Models\LeadershipReview;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Person;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Automation\ReportOutputValidator;
use App\Services\LeadershipReview\LeadershipReviewComposer;
use App\Services\LeadershipReview\LeadershipReviewDispatchService;
use App\Services\LeadershipReview\LeadershipReviewFindings;
use App\Services\LeadershipReview\LeadershipReviewGenerator;
use App\Services\LeadershipReview\LeadershipReviewMetrics;
use App\Services\LeadershipReview\LeadershipReviewPeriod;
use App\Services\LeadershipReview\LeadershipReviewService;
use App\Services\LeadershipReview\LeadershipReviewTelegramRenderer;
use App\Services\LeadershipReview\LeadershipReviewWordingGuard;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class LeadershipReviewTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_and_regular_user_cannot_open_leadership(): void
    {
        $user = null;

        try {
            $this->get(route('jarvis.leadership.index'))->assertRedirect(route('login'));
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('jarvis.leadership.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_metrics_cover_coverage_overdue_and_concentration(): void
    {
        $metrics = (new LeadershipReviewMetrics)->compute($this->fixtureCollected());

        $this->assertSame(12, $metrics['commitments_total']);
        $this->assertSame(3, $metrics['commitments_overdue']);
        $this->assertSame(4, $metrics['commitments_without_deadline']);
        $this->assertSame(2, $metrics['commitments_without_person']);
        $this->assertSame(1, $metrics['likely_done_unconfirmed']);
        $this->assertSame(11, $metrics['meeting_action_items']);
        $this->assertSame(8, $metrics['meeting_actions_without_owner']);
        $this->assertSame(8, $metrics['meeting_actions_without_deadline']);
        $this->assertGreaterThan(0, $metrics['reopened_topics']);
        $this->assertSame(9, $metrics['workload_max_active']);
        $this->assertSame(1, $metrics['workload_person_id']);
        $this->assertNotNull($metrics['owner_coverage_pct']);
        $this->assertNotNull($metrics['deadline_coverage_pct']);
        $this->assertNotNull($metrics['overdue_ratio']);
    }

    public function test_findings_are_grounded_with_evidence_and_no_personality(): void
    {
        $collected = $this->fixtureCollected();
        $metrics = (new LeadershipReviewMetrics)->compute($collected);
        $pack = (new LeadershipReviewFindings)->build($collected, $metrics, null, OwnerLocale::Uk);
        $categories = array_map(static fn (array $row): string => (string) $row['category'], $pack['findings']);
        $guard = new LeadershipReviewWordingGuard;

        $this->assertContains(LeadershipFindingCategory::Ownership->value, $categories);
        $this->assertContains(LeadershipFindingCategory::Deadlines->value, $categories);
        $this->assertContains(LeadershipFindingCategory::FollowUp->value, $categories);
        $this->assertContains(LeadershipFindingCategory::DecisionFollowthrough->value, $categories);
        $this->assertContains(LeadershipFindingCategory::OwnerDependency->value, $categories);
        $this->assertContains(LeadershipFindingCategory::WorkloadConcentration->value, $categories);

        foreach ($pack['findings'] as $finding) {
            $this->assertNull($guard->rejectReason((string) ($finding['observation'] ?? '')));
            $this->assertNull($guard->rejectReason((string) ($finding['title'] ?? '')));
            if (in_array((string) $finding['severity'], [LeadershipFindingSeverity::High->value, LeadershipFindingSeverity::Critical->value], true)) {
                $this->assertNotEmpty($finding['evidence_refs']);
                foreach ($finding['evidence_refs'] as $ref) {
                    $this->assertGreaterThan(0, (int) ($ref['id'] ?? 0));
                }
            }
        }
    }

    public function test_one_meeting_has_insufficient_trend_and_enough_data_has_trend(): void
    {
        $small = (new LeadershipReviewFindings)->build(
            ['commitments' => [], 'meetings' => [['id' => 1, 'analyzed' => true, 'action_items' => [], 'decisions' => [], 'topics' => []]], 'people' => [], 'projects' => [], 'automation_runs' => []],
            ['sample_meetings' => 1, 'sample_commitments' => 0, 'deadline_coverage_pct' => 50, 'owner_coverage_pct' => 50],
            ['sample_meetings' => 1, 'sample_commitments' => 0, 'deadline_coverage_pct' => 20, 'owner_coverage_pct' => 40],
            OwnerLocale::Uk,
        );
        $this->assertTrue($small['insufficient_trend']);

        $enough = (new LeadershipReviewFindings)->build(
            $this->fixtureCollected(),
            ['sample_meetings' => 4, 'sample_commitments' => 12, 'deadline_coverage_pct' => 81, 'owner_coverage_pct' => 70, 'commitments_total' => 12],
            ['sample_meetings' => 4, 'sample_commitments' => 12, 'deadline_coverage_pct' => 62, 'owner_coverage_pct' => 40],
            OwnerLocale::Uk,
        );
        $this->assertFalse($enough['insufficient_trend']);
        $kinds = array_map(static fn (array $row): string => (string) ($row['kind'] ?? ''), $enough['trends']);
        $this->assertContains('improvement', $kinds);
        $this->assertStringContainsString('62', (string) ($enough['trends'][0]['observation'] ?? ''));
        $this->assertStringContainsString('81', (string) ($enough['trends'][0]['observation'] ?? ''));
        $this->assertStringNotContainsString('стиля управления', (string) ($enough['trends'][0]['observation'] ?? ''));
    }

    public function test_ai_personality_and_hallucination_fall_back_to_deterministic(): void
    {
        $user = new User;
        $pack = ['attention' => [['title' => 'Немає owner', 'observation' => 'У 4 action items немає власника.', 'metric' => ['value' => 4]]], 'strengths' => []];
        $metrics = ['commitments_total' => 12, 'meeting_action_items' => 8];
        $composer = new LeadershipReviewComposer(new LeadershipReviewWordingGuard, new ReportOutputValidator, $this->synth('Сергей ленивый'));
        $rejected = $composer->rejectAi('Сергей ленивый', $pack, $metrics, OwnerLocale::Uk, ['Сергій']);
        $this->assertSame('personality', $rejected);

        $hallucination = $composer->rejectAi('У 99 зустрічах немає owner.', $pack, $metrics, OwnerLocale::Uk, ['Сергій']);
        $this->assertSame('invented_number', $hallucination);

        $disabled = (new LeadershipReviewComposer(new LeadershipReviewWordingGuard, new ReportOutputValidator, null))
            ->compose($user, $pack, $metrics, [], OwnerLocale::Uk, ['Сергій']);
        $this->assertFalse($disabled['ai_used']);
        $this->assertSame('deterministic', $disabled['generated_by']);
    }

    public function test_telegram_is_compact(): void
    {
        $text = (new LeadershipReviewTelegramRenderer)->render(
            'summary',
            [
                'attention' => [
                    ['title' => 'Немає owner'],
                    ['title' => 'Немає дедлайну'],
                    ['title' => 'Follow-up'],
                ],
                'strengths' => [['observation' => 'Покриття дедлайнами покращилось з 62% до 81%.']],
            ],
            '/lavr/leadership/1',
            OwnerLocale::Uk,
        );

        $this->assertStringContainsString('Leadership Review', $text);
        $this->assertStringContainsString('3 зони уваги', $text);
        $this->assertStringContainsString('Сильні сторони', $text);
        $this->assertStringContainsString('[Open in LAVR]', $text);
        $this->assertLessThan(1200, mb_strlen($text));
    }

    public function test_workspace_generate_and_drill_down_and_locales(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->bindDeterministicComposer();
            $this->seedSynthetic($user);
            $this->actingAs($user)->post(route('jarvis.leadership.generate'), [
                'review_type' => 'owner',
                'period' => '30',
            ])->assertRedirect();

            $review = LeadershipReview::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($review);
            $this->assertContains($review->status, [LeadershipReviewStatus::Ready, LeadershipReviewStatus::Partial]);

            $page = $this->inertia($this->actingAs($user)->get(route('jarvis.leadership.show', $review)));
            $this->assertSame('uk', $page['props']['locale'] ?? 'uk');
            $serialized = $page['props']['review'];
            $this->assertNotEmpty($serialized['attention']);
            $this->assertNotEmpty($serialized['attention'][0]['evidence_refs'] ?? []);
            $href = $serialized['attention'][0]['evidence_refs'][0]['href'] ?? '';
            $this->assertNotSame('', $href);

            $person = Person::query()->where('user_id', $user->id)->where('display_name', 'Maryna')->first();
            $personPage = $this->inertia($this->actingAs($user)->get(route('jarvis.people.show', $person)));
            $this->assertArrayHasKey('operational', $personPage['props']);
            $this->assertArrayNotHasKey('score', $personPage['props']['operational']['metrics'] ?? []);

            $project = $person->projects()->first() ?? app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
            $projectPage = $this->inertia($this->actingAs($user)->get(route('jarvis.workspace.projects.show', $project)));
            $this->assertArrayHasKey('process', $projectPage['props']);

            $meeting = Meeting::query()->where('user_id', $user->id)->first();
            $meetingPage = $this->inertia($this->actingAs($user)->get(route('jarvis.meetings.show', $meeting)));
            $this->assertArrayHasKey('meeting_quality', $meetingPage['props']);

            $this->actingAs($user)->get(route('leadership-reviews.index'))->assertOk();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_scheduled_weekly_is_idempotent_and_manual_creates_history(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->bindDeterministicComposer();
            UserProductivitySetting::query()->create([
                'user_id' => $user->id,
                'leadership_review_enabled' => true,
                'leadership_review_weekday' => 1,
                'leadership_review_local_time' => '09:00',
                'leadership_review_telegram' => false,
                'leadership_review_inbox' => true,
            ]);
            $this->seedSynthetic($user);
            $monday = CarbonImmutable::parse('2026-09-14 09:00:00', 'Europe/Rome')->utc();
            $this->travelTo($monday);
            $this->assertSame(1, app(LeadershipReviewDispatchService::class)->dispatchDue());
            $this->assertSame(0, app(LeadershipReviewDispatchService::class)->dispatchDue());
            $first = LeadershipReview::query()->where('user_id', $user->id)->where('origin', 'scheduled')->count();
            $this->assertSame(1, $first);

            app(LeadershipReviewService::class)->generateNow($user, ['review_type' => 'owner', 'period' => '30']);
            $this->assertSame(2, LeadershipReview::query()->where('user_id', $user->id)->count());
        } finally {
            $this->travelBack();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_deterministic_pipeline_persists_without_ai(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->bindDeterministicComposer();
            $this->seedSynthetic($user);
            $period = LeadershipReviewPeriod::fromInput(['period' => '30'], CarbonImmutable::now('UTC'), 'Europe/Rome');
            $review = app(LeadershipReviewGenerator::class)->generate($user, LeadershipReviewType::Owner, $period, 'manual', null, null, null, ['telegram' => false, 'inbox' => false]);
            $this->assertSame('deterministic', $review->generated_by);
            $this->assertGreaterThan(0, (int) data_get($review->metrics_json, 'commitments_total'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureCollected(): array
    {
        $actionsMissing = array_fill(0, 5, ['task' => 'Check', 'owner' => '', 'deadline_raw' => '', 'deadline_at' => null]);
        $actionsOwned = [
            ['task' => 'Send Chicago budget file', 'owner' => 'Owner', 'deadline_raw' => '2026-09-20', 'deadline_at' => now()->toIso8601String()],
            ['task' => 'Confirm invoice', 'owner' => 'Maryna', 'deadline_raw' => '2026-09-18', 'deadline_at' => now()->toIso8601String()],
            ['task' => 'Share deck', 'owner' => 'Owner', 'deadline_raw' => '2026-09-19', 'deadline_at' => now()->toIso8601String()],
        ];

        $meetings = [];
        for ($i = 1; $i <= 4; $i++) {
            $meetings[] = [
                'id' => $i,
                'title' => 'Chicago sync '.$i,
                'project_id' => 10,
                'project_name' => 'Chicago',
                'started_at' => now()->subDays($i)->toIso8601String(),
                'analyzed' => true,
                'action_items' => $i === 1 ? array_merge($actionsMissing, $actionsOwned) : [['task' => 'Follow Chicago', 'owner' => '', 'deadline_raw' => '', 'deadline_at' => null]],
                'decisions' => [['text' => 'Keep Chicago timeline']],
                'topics' => ['Chicago timeline'],
                'open_questions' => 1,
                'follow_ups' => 1,
                'unresolved_identities' => ['someone'],
                'risks_count' => 1,
            ];
        }

        $commitments = [];
        for ($i = 1; $i <= 12; $i++) {
            $overdue = $i <= 3;
            $noDeadline = $i >= 4 && $i <= 7;
            $unresolved = $i >= 8 && $i <= 9;
            $likely = $i === 10;
            $commitments[] = [
                'id' => $i,
                'title' => 'Item '.$i,
                'person_id' => $unresolved ? null : ($i === 12 ? 2 : 1),
                'person_name' => $unresolved ? '' : ($i === 12 ? 'Owner' : 'Maryna'),
                'unresolved_person' => $unresolved,
                'project_id' => 10,
                'meeting_id' => 1,
                'deadline_at' => $noDeadline ? null : now()->subDays($overdue ? 3 : -2)->toIso8601String(),
                'deadline_raw' => $noDeadline ? 'soon' : '',
                'has_expected_result' => true,
                'status' => $overdue ? 'overdue' : ($likely ? 'likely_done' : 'open'),
                'last_notified_at' => $overdue ? null : now()->toIso8601String(),
                'created_at' => now()->subDays(5)->toIso8601String(),
            ];
        }

        return [
            'commitments' => $commitments,
            'meetings' => $meetings,
            'projects' => [['id' => 10, 'name' => 'Chicago', 'owner_person_id' => null]],
            'people' => [
                ['id' => 1, 'display_name' => 'Maryna', 'primary_email' => '', 'normalized_name' => 'maryna'],
                ['id' => 2, 'display_name' => 'Owner', 'primary_email' => 'owl@example.com', 'normalized_name' => 'owner'],
            ],
            'automation_runs' => [['id' => 5, 'automation_type' => 'watcher', 'status' => 'failed']],
            'owner_person_ids' => [2],
            'owner_name' => 'Owner',
            'known_names' => ['Maryna', 'Owner'],
            'snapshot' => ['sources_attempted' => 5, 'sources_succeeded' => 5, 'sources_failed' => 0],
        ];
    }

    private function seedSynthetic(User $user): void
    {
        $ownerPerson = Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => $user->name,
            'primary_email' => $user->email,
            'normalized_name' => ProjectNameNormalizer::normalize((string) $user->name),
        ]);
        $maryna = Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => 'Maryna',
            'normalized_name' => ProjectNameNormalizer::normalize('Maryna'),
        ]);
        $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
        $maryna->projects()->attach($project->id, ['role' => 'owner']);

        for ($i = 0; $i < 4; $i++) {
            $meeting = Meeting::factory()->create([
                'user_id' => $user->id,
                'project_id' => $project->id,
                'title' => 'Chicago sync '.$i,
                'started_at' => now('Europe/Rome')->subDays($i + 1),
                'analysis_status' => MeetingAnalysisStatus::Completed,
            ]);
            $analysis = MeetingAnalysis::query()->create([
                'meeting_id' => $meeting->id,
                'version' => 1,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => [
                    'summary' => ['executive' => 'Chicago', 'outcomes' => [], 'attention' => []],
                    'topics' => ['Chicago timeline'],
                    'decisions' => [['text' => 'Keep Chicago timeline']],
                    'action_items' => [
                        ['task' => 'Check status', 'owner' => '', 'deadline_raw' => '', 'deadline_at' => null],
                        ['task' => 'Send file', 'owner' => 'Maryna', 'deadline_raw' => 'Friday', 'deadline_at' => now()->addDays(2)->toIso8601String()],
                    ],
                    'open_questions' => [['text' => 'Who owns follow-up?']],
                    'follow_ups' => [['text' => 'Confirm owner']],
                    'unresolved_identities' => ['someone'],
                    'risks' => [['text' => 'Blocked']],
                ],
            ]);
            $meeting->forceFill([
                'current_analysis_id' => $analysis->id,
                'analysis_status' => MeetingAnalysisStatus::Completed,
            ])->save();
        }

        for ($i = 0; $i < 12; $i++) {
            $overdue = $i < 3;
            $noDeadline = $i >= 3 && $i < 7;
            $unresolved = $i >= 7 && $i < 9;
            $likely = $i === 9;
            Commitment::factory()->create([
                'user_id' => $user->id,
                'person_id' => $unresolved ? null : $maryna->id,
                'unresolved_person' => $unresolved,
                'project_id' => $project->id,
                'title' => 'Commitment '.$i,
                'status' => $overdue
                    ? CommitmentEffectiveStatus::Overdue
                    : ($likely ? CommitmentEffectiveStatus::LikelyDone : CommitmentEffectiveStatus::Open),
                'lifecycle_status' => $likely ? CommitmentLifecycleStatus::LikelyDone : CommitmentLifecycleStatus::Open,
                'deadline_at' => $noDeadline ? null : now('Europe/Rome')->subDays($overdue ? 2 : -3),
                'detected_at' => now('Europe/Rome')->subDays(4),
            ]);
        }

        Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $ownerPerson->id,
            'project_id' => $project->id,
            'title' => 'Owner item',
            'status' => CommitmentEffectiveStatus::Open,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'deadline_at' => now('Europe/Rome')->addDays(4),
        ]);
    }

    private function bindDeterministicComposer(): void
    {
        $this->app->instance(LeadershipReviewComposer::class, new LeadershipReviewComposer(
            new LeadershipReviewWordingGuard,
            new ReportOutputValidator,
            null,
        ));
        $this->app->forgetInstance(LeadershipReviewGenerator::class);
        $this->app->forgetInstance(LeadershipReviewService::class);
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

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner, 'timezone' => 'Europe/Rome', 'name' => 'Owner'])->save();

        return $user;
    }

    private function synth(string $text): SynthesizesProductivityBrief
    {
        return new class($text) implements SynthesizesProductivityBrief
        {
            public function __construct(private readonly string $text) {}

            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return $this->text;
            }
        };
    }
}
