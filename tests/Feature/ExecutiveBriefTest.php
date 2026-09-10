<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\ExecutiveBriefPriority;
use App\Enums\ExecutiveBriefType;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\OwnerLocale;
use App\Enums\UserRole;
use App\Models\AutomationRun;
use App\Models\Commitment;
use App\Models\ExecutiveBrief;
use App\Models\IntegrationAccount;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\Person;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Automation\ReportOutputValidator;
use App\Services\ExecutiveBrief\ExecutiveBriefAssembler;
use App\Services\ExecutiveBrief\ExecutiveBriefCollector;
use App\Services\ExecutiveBrief\ExecutiveBriefComposer;
use App\Services\ExecutiveBrief\ExecutiveBriefDispatchService;
use App\Services\ExecutiveBrief\ExecutiveBriefGenerator;
use App\Services\ExecutiveBrief\ExecutiveBriefItem;
use App\Services\ExecutiveBrief\ExecutiveBriefTelegramRenderer;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ExecutiveBriefTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_and_regular_user_cannot_open_briefs(): void
    {
        $user = null;

        try {
            $this->get(route('jarvis.briefs.index'))->assertRedirect(route('login'));
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('jarvis.briefs.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_collects_commitments_meetings_gmail_and_blocked_integration(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->seedScenario($user);
            $collected = $this->collector($user, calendarThrows: false)->collect(
                $user,
                ExecutiveBriefType::Morning,
                CarbonImmutable::now('UTC'),
                'Europe/Rome',
            );

            $types = array_map(fn (ExecutiveBriefItem $item): string => $item->type, $collected['items']);
            $this->assertContains('commitment_overdue', $types);
            $this->assertContains('commitment_due_today', $types);
            $this->assertContains('commitment_likely_done', $types);
            $this->assertContains('meeting_today', $types);
            $this->assertContains('meeting_risk', $types);
            $this->assertContains('email_important', $types);
            $this->assertContains('integration_blocked', $types);
            $this->assertNotContains('commitment_open', $types);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_calendar_timeout_marks_partial_without_attention_item(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $collected = $this->collector($user, calendarThrows: true)->collect(
                $user,
                ExecutiveBriefType::Morning,
                CarbonImmutable::now('UTC'),
                'Europe/Rome',
            );

            $this->assertContains('Календар тимчасово недоступний.', $collected['snapshot']['errors']);
            $types = array_map(fn (ExecutiveBriefItem $item): string => $item->type, $collected['items']);
            $this->assertNotContains('calendar_event', $types);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_assembler_puts_overdue_and_blocked_in_attention_and_dedupes(): void
    {
        $overdue = new ExecutiveBriefItem(
            type: 'commitment_overdue',
            priority: ExecutiveBriefPriority::Critical,
            title: 'Budget',
            summary: 'Overdue budget',
            section: 'overdue',
            dedupeKey: 'commitment:1',
            confidence: 'high',
            score: 92,
            sourceType: 'commitment',
            sourceId: 1,
            projectId: 4,
            commitmentId: 1,
        );
        $blocked = new ExecutiveBriefItem(
            type: 'integration_blocked',
            priority: ExecutiveBriefPriority::Critical,
            title: 'Gmail',
            summary: 'Reconnect Gmail',
            section: 'attention',
            dedupeKey: 'integration:google:blocked',
            confidence: 'high',
            score: 88,
            sourceType: 'integration',
        );
        $fyi = new ExecutiveBriefItem(
            type: 'email_actionable',
            priority: ExecutiveBriefPriority::Low,
            title: 'FYI',
            summary: 'Newsletter gist',
            section: 'inbox',
            dedupeKey: 'email:fyi',
            confidence: 'low',
            score: 12,
            sourceType: 'gmail',
        );

        $assembled = (new ExecutiveBriefAssembler)->assemble([$overdue, $blocked, $fyi], null);
        $attentionTypes = array_column($assembled['sections']['attention'], 'type');
        $this->assertSame(['commitment_overdue', 'integration_blocked'], $attentionTypes);
        $this->assertSame([], $assembled['sections']['overdue']);
        $this->assertCount(1, $assembled['sections']['inbox']);
        $this->assertSame([], $assembled['sections']['projects']);
    }

    public function test_delta_suppresses_stale_fyi_and_keeps_unresolved_overdue(): void
    {
        $previous = ExecutiveBrief::factory()->make([
            'sections_json' => [
                'inbox' => [['dedupe_key' => 'email:old']],
                'attention' => [['dedupe_key' => 'commitment:1']],
            ],
        ]);
        $items = [
            new ExecutiveBriefItem('email_actionable', ExecutiveBriefPriority::Normal, 'Old', 'old fyi', 'inbox', 'email:old', 'low', 40, sourceType: 'gmail'),
            new ExecutiveBriefItem('commitment_overdue', ExecutiveBriefPriority::Critical, 'Budget', 'still late', 'overdue', 'commitment:1', 'high', 92, sourceType: 'commitment', sourceId: 1, commitmentId: 1),
        ];
        $assembled = (new ExecutiveBriefAssembler)->assemble($items, $previous);
        $this->assertSame([], $assembled['sections']['inbox']);
        $this->assertStringContainsString('ще не вирішено', $assembled['sections']['attention'][0]['summary']);
    }

    public function test_cluster_merges_email_with_commitment_and_drops_duplicate(): void
    {
        $commitment = new ExecutiveBriefItem(
            type: 'commitment_overdue',
            priority: ExecutiveBriefPriority::Critical,
            title: 'Budget',
            summary: 'Sergiy overdue budget',
            section: 'overdue',
            dedupeKey: 'commitment:1',
            confidence: 'high',
            score: 92,
            sourceType: 'commitment',
            sourceId: 1,
            personId: 2,
            commitmentId: 1,
            evidence: ['person' => 'Sergiy'],
        );
        $email = new ExecutiveBriefItem(
            type: 'email_important',
            priority: ExecutiveBriefPriority::High,
            title: 'Budget tonight',
            summary: 'Sergiy — tonight',
            section: 'inbox',
            dedupeKey: 'email:sergiy:budget',
            confidence: 'medium',
            score: 68,
            sourceType: 'gmail',
            evidence: ['sender' => 'Sergiy <s@test>'],
        );
        $assembled = (new ExecutiveBriefAssembler)->assemble([$commitment, $email], null);
        $this->assertCount(1, $assembled['sections']['attention']);
        $this->assertSame([], $assembled['sections']['inbox']);
        $this->assertStringContainsString('tonight', $assembled['sections']['attention'][0]['summary']);
    }

    public function test_composer_falls_back_when_ai_is_invalid_or_disabled(): void
    {
        $sections = ['attention' => [['title' => 'Budget', 'summary' => 'Overdue budget', 'source_id' => 1]]];
        $snapshot = ['errors' => []];
        $user = new User;

        $disabled = (new ExecutiveBriefComposer(new ReportOutputValidator, null))
            ->compose($user, ExecutiveBriefType::Morning, $sections, $snapshot, OwnerLocale::Uk);
        $this->assertFalse($disabled['ai_used']);
        $this->assertStringContainsString('Overdue budget', $disabled['text']);

        $invalid = (new ExecutiveBriefComposer(new ReportOutputValidator, $this->synth('{"items":[{"source_id":999}]}')))
            ->compose($user, ExecutiveBriefType::Morning, $sections, $snapshot, OwnerLocale::Uk);
        $this->assertFalse($invalid['ai_used']);

        $truncated = (new ExecutiveBriefComposer(new ReportOutputValidator, $this->synth('Overdue budget,')))
            ->compose($user, ExecutiveBriefType::Morning, $sections, $snapshot, OwnerLocale::Uk);
        $this->assertFalse($truncated['ai_used']);

        $this->assertSame('unknown_source', (new ExecutiveBriefComposer)->rejectInventedItems([
            ['source_id' => 999],
        ], [1]));
    }

    public function test_telegram_renders_one_compact_message_and_truncates(): void
    {
        $renderer = new ExecutiveBriefTelegramRenderer;
        $sections = [
            'attention' => [
                ['summary' => 'Overdue budget'],
                ['summary' => 'Reconnect Gmail'],
            ],
            'today' => [['summary' => 'Standup']],
        ];
        $text = $renderer->render('2 потребують уваги.', $sections, [], OwnerLocale::Uk, 3500);
        $this->assertStringContainsString('Ранковий бриф', $text);
        $this->assertStringContainsString('Відкрити в LAVR', $text);
        $this->assertSame(1, substr_count($text, '☀️'));

        $long = [];
        for ($i = 0; $i < 40; $i++) {
            $long[] = ['summary' => str_repeat('ризик '.$i.' ', 20)];
        }
        $truncated = $renderer->render('багато', ['attention' => $long, 'today' => $long], [], OwnerLocale::Uk, 200);
        $this->assertStringContainsString('Відкрити в LAVR', $truncated);
        $this->assertLessThanOrEqual(400, mb_strlen($truncated));
    }

    public function test_scheduled_dispatch_is_idempotent_and_respects_weekends(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->settings($user, weekends: false);
            $this->fakeTelegram();

            $saturday = CarbonImmutable::parse('2026-09-05 09:00:00', 'Europe/Rome')->utc();
            $this->travelTo($saturday);
            $this->assertSame(0, app(ExecutiveBriefDispatchService::class)->dispatchDue());

            $monday = CarbonImmutable::parse('2026-09-07 08:30:00', 'Europe/Rome')->utc();
            $this->travelTo($monday);
            $this->assertSame(1, app(ExecutiveBriefDispatchService::class)->dispatchDue());
            $this->assertSame(0, app(ExecutiveBriefDispatchService::class)->dispatchDue());
            $this->assertSame(1, ExecutiveBrief::query()->where('user_id', $user->id)->where('origin', 'scheduled')->count());

            $before = CarbonImmutable::parse('2026-09-07 08:00:00', 'Europe/Rome')->utc();
            $this->travelTo($before);
            $settings = UserProductivitySetting::query()->where('user_id', $user->id)->first();
            $settings->forceFill(['last_morning_brief_at' => null])->save();
            $this->assertFalse(app(ExecutiveBriefDispatchService::class)->isDue($settings->fresh(), $user, $before));
        } finally {
            $this->travelBack();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_manual_generate_creates_history_and_one_telegram_delivery(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->createTemporaryTelegramIdentity($user, '911111');
            $fake = $this->fakeTelegram();
            $this->settings($user, inbox: false);

            $first = app(ExecutiveBriefGenerator::class)->generate(
                $user,
                ExecutiveBriefType::Morning,
                CarbonImmutable::now('UTC'),
                'manual',
                null,
                ['telegram' => true, 'inbox' => false],
            );
            $second = app(ExecutiveBriefGenerator::class)->generate(
                $user,
                ExecutiveBriefType::Morning,
                CarbonImmutable::now('UTC'),
                'manual',
                (int) $first->id,
                ['telegram' => true, 'inbox' => false],
            );

            $this->assertNotSame($first->id, $second->id);
            $this->assertSame($first->id, $second->regenerated_from_id);
            $this->assertCount(2, $fake->sent);
            $this->assertSame('brief_'.$first->id, $fake->sent[0]['start']);
            $this->assertSame(2, AutomationRun::query()->where('user_id', $user->id)->count());

            $page = $this->inertia($this->actingAs($user)->get(route('jarvis.briefs.show', $first)));
            $this->assertSame('Jarvis/Briefs/Show', $page['component']);
            $this->assertSame($first->id, $page['props']['brief']['id']);

            $this->actingAs($user)->get(route('jarvis.today.show'))->assertOk();
            $this->actingAs($user)->get(route('executive-briefs.index'))->assertOk();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_dst_local_time_uses_owner_timezone(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->settings($user);
            $settings = UserProductivitySetting::query()->where('user_id', $user->id)->first();
            $before = CarbonImmutable::parse('2026-03-27 08:00:00', 'Europe/Rome')->utc();
            $after = CarbonImmutable::parse('2026-03-30 08:30:00', 'Europe/Rome')->utc();
            $this->assertFalse(app(ExecutiveBriefDispatchService::class)->isDue($settings, $user, $before));
            $this->assertTrue(app(ExecutiveBriefDispatchService::class)->isDue($settings, $user, $after));
        } finally {
            $this->deleteTemporaryUser($user);
        }
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
        $user->forceFill(['role' => UserRole::Owner, 'timezone' => 'Europe/Rome'])->save();

        return $user;
    }

    private function settings(User $user, bool $weekends = false, bool $inbox = true): UserProductivitySetting
    {
        return UserProductivitySetting::query()->create([
            'user_id' => $user->id,
            'morning_brief_enabled' => true,
            'morning_brief_local_time' => '08:30',
            'morning_brief_telegram' => true,
            'morning_brief_inbox' => $inbox,
            'morning_brief_weekends' => $weekends,
        ]);
    }

    private function seedScenario(User $user): void
    {
        $person = Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => 'Sergiy',
            'normalized_name' => ProjectNameNormalizer::normalize('Sergiy'),
        ]);
        $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
        Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'title' => 'Send budget',
            'status' => CommitmentEffectiveStatus::Overdue,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'deadline_at' => now('Europe/Rome')->subDay(),
        ]);
        Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'title' => 'Deck today',
            'status' => CommitmentEffectiveStatus::DueSoon,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'deadline_at' => now('Europe/Rome')->setTime(16, 0),
        ]);
        Commitment::factory()->create([
            'user_id' => $user->id,
            'title' => 'Likely sent',
            'status' => CommitmentEffectiveStatus::LikelyDone,
            'lifecycle_status' => CommitmentLifecycleStatus::LikelyDone,
        ]);
        Commitment::factory()->create([
            'user_id' => $user->id,
            'title' => 'Already done',
            'status' => CommitmentEffectiveStatus::Confirmed,
            'lifecycle_status' => CommitmentLifecycleStatus::Confirmed,
        ]);
        Meeting::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Standup',
            'started_at' => now('Europe/Rome')->setTime(11, 0),
        ]);
        $recent = Meeting::factory()->create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'title' => 'Budget call',
            'started_at' => now('Europe/Rome')->subDay()->setTime(15, 0),
        ]);
        $analysis = MeetingAnalysis::query()->create([
            'meeting_id' => $recent->id,
            'version' => 1,
            'status' => MeetingAnalysisStatus::Completed,
            'result_json' => [
                'risks' => [['text' => 'Budget may slip']],
                'decisions' => [['text' => 'Need owner call']],
                'action_items' => [],
            ],
        ]);
        $recent->forceFill([
            'current_analysis_id' => $analysis->id,
            'analysis_status' => MeetingAnalysisStatus::Completed,
        ])->save();
        IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'external_account_id' => 'blocked-'.$user->id,
            'status' => IntegrationAccountStatus::Revoked,
            'last_error_code' => 'blocked_auth',
        ]);
    }

    private function collector(User $user, bool $calendarThrows): ExecutiveBriefCollector
    {
        $this->connectGoogle($user);
        $gmail = new class
        {
            public function searchMessages(IntegrationAccount $account, string $query, array $options = []): array
            {
                return [
                    'messages' => [
                        ['id' => 'm1', 'from' => 'Sergiy', 'subject' => 'Urgent budget', 'snippet' => 'will send tonight', 'body' => 'ok'],
                        ['id' => 'm2', 'from' => 'Marina', 'subject' => 'Action required invoice', 'snippet' => 'please confirm'],
                    ],
                ];
            }
        };
        $calendar = $calendarThrows
            ? new class
            {
                public function listEvents(IntegrationAccount $account, string $calendarId, array $options = []): array
                {
                    throw new \RuntimeException('timeout');
                }
            }
        : new class
        {
            public function listEvents(IntegrationAccount $account, string $calendarId, array $options = []): array
            {
                return ['events' => [
                    ['id' => 'e1', 'title' => 'Lunch', 'start' => now('Europe/Rome')->setTime(13, 0)->toIso8601String()],
                ]];
            }
        };

        return new ExecutiveBriefCollector(
            app(IntegrationAccountService::class),
            $calendar,
            $gmail,
        );
    }

    private function connectGoogle(User $user): void
    {
        IntegrationAccount::query()->updateOrCreate(
            ['user_id' => $user->id, 'provider' => 'google', 'external_account_id' => 'google-sub-'.$user->id],
            [
                'status' => IntegrationAccountStatus::Connected,
                'external_account_email' => 'owner@example.test',
                'scopes' => ['https://www.googleapis.com/auth/gmail.readonly'],
            ],
        );
    }

    private function fakeTelegram(): object
    {
        $fake = new class implements SendsReminderTelegram
        {
            /** @var list<array{chatId: string, text: string, start: ?string}> */
            public array $sent = [];

            public function send(string $chatId, string $text, ?string $webAppStartParam = null): void
            {
                $this->sent[] = ['chatId' => $chatId, 'text' => $text, 'start' => $webAppStartParam];
            }
        };
        $this->app->instance(SendsReminderTelegram::class, $fake);
        $this->app->forgetInstance(ExecutiveBriefGenerator::class);

        return $fake;
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
