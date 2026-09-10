<?php

namespace Tests\Feature\Reports;

use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ReferenceOutcome;
use App\Enums\ScheduledReportPeriodMode;
use App\Enums\ScheduledReportType;
use App\Enums\TopicContinuityMode;
use App\Enums\UserRole;
use App\Models\JarvisNotification;
use App\Models\Message;
use App\Models\ScheduledReport;
use App\Models\ScheduledReportRun;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Ai\DTO\ToolCall;
use App\Services\ConversationIntelligence\ConversationalEntity;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\Google\GoogleGmailService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Productivity\SynthesizesProductivityBrief;
use App\Services\Reports\ScheduledReportCollector;
use App\Services\Reports\ScheduledReportComposer;
use App\Services\Reports\ScheduledReportDispatchService;
use App\Services\Reports\ScheduledReportIntent;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tasks\TaskService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Reports\CreateScheduledReportTool;
use App\Services\Tools\Reports\UpdateScheduledReportTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\Watchers\CreateWatcherTool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ScheduledReportsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_tomorrow_plan_phrase_creates_a_scheduled_report_not_a_watcher_or_reminder(): void
    {
        $user = null;

        try {
            Queue::fake();
            $user = $this->owner();
            $inbound = $this->inbound($user, 'каждый вечер в 22:00 присылай мне отчет о том какие планы на завтра. исходя из наших планов, календаря и связанного календаря. только не напоминание а отчет');
            $context = new ToolExecutionContext($user, $inbound->conversation, $inbound);

            $reportResult = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('c1', CreateScheduledReportTool::NAME, []),
                $context,
            );
            $watcherResult = app(CreateWatcherTool::class)->execute(
                new ToolCall('c2', CreateWatcherTool::NAME, []),
                $context,
            );
            $reminderResult = app(CreateReminderTool::class)->execute(
                new ToolCall('c3', CreateReminderTool::NAME, [
                    'text' => 'планы на завтра',
                    'run_at_local' => '2026-09-08T22:00:00+02:00',
                ]),
                $context,
            );

            $this->assertTrue($reportResult->success);
            $this->assertArrayHasKey('report_id', $reportResult->payload);
            $this->assertFalse($watcherResult->success);
            $this->assertSame('use_scheduled_report', $watcherResult->payload['error'] ?? null);
            $this->assertFalse($reminderResult->success);
            $this->assertSame('use_scheduled_report', $reminderResult->payload['error'] ?? null);
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());

            $report = ScheduledReport::query()->whereKey($reportResult->payload['report_id'])->first();
            $this->assertNotNull($report);
            $this->assertSame(ScheduledReportType::TomorrowPlan, $report->report_type);
            $this->assertSame(ScheduledReportPeriodMode::Tomorrow, $report->period_mode);
            $this->assertSame('22:00', $report->local_time);
            $this->assertSame('Europe/Rome', $report->timezone);
            $this->assertContains('tasks', array_column($report->sources, 'type'));
            $this->assertContains('google_calendar', array_column($report->sources, 'type'));
            $calendar = collect($report->sources)->firstWhere('type', 'google_calendar');
            $this->assertSame('all_relevant', $calendar['calendar_scope'] ?? null);
            $this->assertContains('Семья', $calendar['calendar_names'] ?? []);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_morning_plan_uses_today_and_mail_groups_digest_uses_gmail_and_groups(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $morning = $this->inbound($user, 'каждое утро в 8:30 отчет с планами на текущий день');
            $mail = $this->inbound($user, 'каждое утро в 9:00 отчет про новые письма и сводку по группам', $morning->conversation_id);

            $morningResult = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('m1', CreateScheduledReportTool::NAME, []),
                new ToolExecutionContext($user, $morning->conversation, $morning),
            );
            $mailResult = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('m2', CreateScheduledReportTool::NAME, []),
                new ToolExecutionContext($user, $mail->conversation, $mail),
            );

            $this->assertTrue($morningResult->success);
            $this->assertTrue($mailResult->success);

            $today = ScheduledReport::query()->whereKey($morningResult->payload['report_id'])->first();
            $digest = ScheduledReport::query()->whereKey($mailResult->payload['report_id'])->first();

            $this->assertSame(ScheduledReportType::DailyPlan, $today->report_type);
            $this->assertSame(ScheduledReportPeriodMode::Today, $today->period_mode);
            $this->assertSame('08:30', $today->local_time);
            $this->assertContains('tasks', array_column($today->sources, 'type'));
            $this->assertContains('google_calendar', array_column($today->sources, 'type'));

            $this->assertSame(ScheduledReportType::MailGroupsDigest, $digest->report_type);
            $this->assertSame(ScheduledReportPeriodMode::SincePreviousReport, $digest->period_mode);
            $this->assertSame('09:00', $digest->local_time);
            $this->assertSame(['gmail', 'telegram_groups'], array_column($digest->sources, 'type'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_mail_and_groups_windows_use_previous_success_or_last_24h(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $now = CarbonImmutable::parse('2026-09-08 07:00:00', 'UTC');
            $report = app(ScheduledReportService::class)->create($user, ScheduledReportIntent::fromInbound(
                'каждое утро в 9:00 отчет про новые письма и сводку по группам',
                $user,
            ) ?? []);
            $collector = new ScheduledReportCollector;

            $first = $collector->window($report, $now, 'Europe/Rome');
            $this->assertTrue($first['start']->equalTo($now->subDay()));

            $report->forceFill(['last_success_at' => $now->subHours(12)])->save();
            $next = $collector->window($report->fresh(), $now, 'Europe/Rome');
            $this->assertTrue($next['start']->equalTo($now->subHours(12)));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_created_before_slot_is_due_today_and_after_slot_waits_until_tomorrow(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 19:39:00', 'UTC'));
            $before = app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на завтра',
                'report_type' => 'tomorrow_plan',
                'period_mode' => 'tomorrow',
                'local_time' => '22:00',
                'sources' => ['tasks'],
            ]);
            $this->assertTrue($before->next_run_at?->equalTo(CarbonImmutable::parse('2026-09-08 20:00:00', 'UTC')));

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 20:01:00', 'UTC'));
            $after = app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на завтра позже',
                'report_type' => 'tomorrow_plan',
                'period_mode' => 'tomorrow',
                'local_time' => '22:00',
                'sources' => ['tasks'],
            ]);
            $this->assertTrue($after->next_run_at?->equalTo(CarbonImmutable::parse('2026-09-09 20:00:00', 'UTC')));
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_partial_source_failure_still_sends_and_duplicate_slot_does_not_double_send(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-08 20:00:00', 'UTC'));
            $report = app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на завтра',
                'report_type' => 'tomorrow_plan',
                'period_mode' => 'tomorrow',
                'local_time' => '22:00',
                'sources' => [
                    ['type' => 'tasks'],
                    ['type' => 'google_calendar', 'calendar_scope' => 'all_relevant'],
                ],
                'delivery' => ['telegram' => false, 'web_notification' => true],
            ]);
            $report->forceFill(['next_run_at' => CarbonImmutable::now('UTC')])->save();

            $dispatch = new ScheduledReportDispatchService(
                new ScheduledReportCollector,
                new ScheduledReportComposer,
                app(JarvisNotificationService::class),
                app(NotificationUrlPolicy::class),
                null,
            );

            $first = $dispatch->run($report->fresh(), $user, CarbonImmutable::now('UTC'));
            $second = $dispatch->run($report->fresh(), $user, CarbonImmutable::now('UTC'));

            $this->assertNotNull($first);
            $this->assertNull($second);
            $this->assertSame(1, ScheduledReportRun::query()->where('scheduled_report_id', $report->id)->count());
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'scheduled_report')->count());
            $this->assertNotEmpty($first->source_errors);
            $this->assertStringContainsString('Календарь сейчас недоступен', (string) $first->body);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_truncated_ai_phrasing_still_delivers_collected_tasks(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-09 06:30:00', 'UTC'));
            app(TaskService::class)->create(
                $user,
                'Заполнить материалы',
                dueAt: CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'),
            );
            $report = app(ScheduledReportService::class)->create($user, [
                'name' => 'Утренний отчёт: планы на сегодня',
                'report_type' => 'daily_plan',
                'period_mode' => 'today',
                'local_time' => '08:30',
                'sources' => [
                    ['type' => 'tasks'],
                    ['type' => 'google_calendar', 'calendar_scope' => 'all_relevant'],
                ],
                'delivery' => ['telegram' => false, 'web_notification' => true],
            ]);
            $report->forceFill(['next_run_at' => CarbonImmutable::now('UTC')])->save();

            $dispatch = new ScheduledReportDispatchService(
                new ScheduledReportCollector,
                new ScheduledReportComposer($this->truncatedReportSynthesizer()),
                app(JarvisNotificationService::class),
                app(NotificationUrlPolicy::class),
                null,
            );
            $run = $dispatch->run($report->fresh(), $user, CarbonImmutable::now('UTC'));

            $this->assertNotNull($run);
            $this->assertStringContainsString('Заполнить материалы', (string) $run->body);
            $this->assertStringContainsString('Календарь сейчас недоступен', (string) $run->body);
            $this->assertStringNotContainsString('Доброе утро. Сводка на сегодня,', (string) $run->body);
            $notification = JarvisNotification::query()
                ->where('user_id', $user->id)
                ->where('source_type', 'scheduled_report')
                ->first();
            $this->assertNotNull($notification);
            $this->assertStringContainsString('Заполнить материалы', (string) $notification->body);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_container_collector_receives_google_calendar_and_gmail(): void
    {
        $collector = app(ScheduledReportCollector::class);
        $reflection = new \ReflectionClass($collector);

        $this->assertInstanceOf(IntegrationAccountService::class, $reflection->getProperty('accounts')->getValue($collector));
        $this->assertInstanceOf(GoogleCalendarService::class, $reflection->getProperty('calendar')->getValue($collector));
        $this->assertInstanceOf(GoogleGmailService::class, $reflection->getProperty('gmail')->getValue($collector));
    }

    public function test_failed_create_does_not_claim_success(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $result = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('c1', CreateScheduledReportTool::NAME, [
                    'report_type' => 'custom_composite',
                    'sources' => ['not-a-source'],
                ]),
                new ToolExecutionContext($user, $conversation),
            );

            $this->assertFalse($result->success);
            $this->assertArrayNotHasKey('report_id', $result->payload);
            $this->assertSame('invalid_sources', $result->payload['error'] ?? null);
            $this->assertSame(0, ScheduledReport::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_reminder_and_gmail_event_wording_stay_on_their_tools(): void
    {
        $user = null;

        try {
            Queue::fake();
            $user = $this->owner();
            $this->connectGoogle($user);
            $reminderInbound = $this->inbound($user, 'Напомни в 9 проверить почту');
            $reminder = app(CreateReminderTool::class)->execute(
                new ToolCall('r1', CreateReminderTool::NAME, [
                    'text' => 'проверить почту',
                    'run_at_local' => '2026-12-15T09:00:00+02:00',
                ]),
                new ToolExecutionContext($user, $reminderInbound->conversation, $reminderInbound),
            );

            $this->assertTrue($reminder->success);
            $this->assertArrayHasKey('reminder_id', $reminder->payload);
            $this->assertArrayNotHasKey('report_id', $reminder->payload);
            $this->assertArrayNotHasKey('watcher_id', $reminder->payload);

            $eventInbound = $this->inbound($user, 'жди письмо от школы', $reminderInbound->conversation_id);
            $watcher = app(CreateWatcherTool::class)->execute(
                new ToolCall('w1', CreateWatcherTool::NAME, [
                    'source' => ['sender_domains' => ['school.it']],
                ]),
                new ToolExecutionContext($user, $eventInbound->conversation, $eventInbound),
            );

            $this->assertTrue($watcher->success);
            $this->assertArrayHasKey('watcher_id', $watcher->payload);
            $this->assertSame('gmail_event', $watcher->payload['kind'] ?? null);
            $this->assertSame(0, ScheduledReport::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_follow_up_adds_family_calendar_to_the_trusted_report_and_ambiguous_asks(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $firstInbound = $this->inbound($user, 'Каждый вечер в 22 планы на завтра.');
            $created = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('c1', CreateScheduledReportTool::NAME, []),
                new ToolExecutionContext($user, $firstInbound->conversation, $firstInbound),
            );
            $this->assertTrue($created->success);
            $reportId = (int) $created->payload['report_id'];

            $followUp = $this->inbound($user, 'Добавь туда семейный календарь.', $firstInbound->conversation_id);
            $working = new WorkingContext(
                topicMode: TopicContinuityMode::Continue,
                referenceOutcome: ReferenceOutcome::Resolved,
                continuitySource: 'recent_tool_results',
                recentToolReferences: [
                    new ConversationalEntity('scheduled_report', 'Планы на завтра', $reportId, true),
                ],
            );
            $updated = app(UpdateScheduledReportTool::class)->execute(
                new ToolCall('u1', UpdateScheduledReportTool::NAME, [
                    'add_source' => [
                        'type' => 'google_calendar',
                        'calendar_scope' => 'all_relevant',
                        'calendar_names' => ['Семья'],
                    ],
                ]),
                new ToolExecutionContext($user, $followUp->conversation, $followUp, working: $working),
            );

            $this->assertTrue($updated->success);
            $report = ScheduledReport::query()->find($reportId);
            $calendar = collect($report->sources)->firstWhere('type', 'google_calendar');
            $this->assertContains('Семья', $calendar['calendar_names'] ?? []);

            app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на сегодня',
                'report_type' => 'daily_plan',
                'period_mode' => 'today',
                'local_time' => '08:30',
                'sources' => ['tasks'],
            ]);
            $ambiguous = $this->inbound($user, 'А в утренний отчет добавь погоду.', $firstInbound->conversation_id);
            $result = app(UpdateScheduledReportTool::class)->execute(
                new ToolCall('u2', UpdateScheduledReportTool::NAME, [
                    'add_source' => ['type' => 'notifications'],
                ]),
                new ToolExecutionContext($user, $ambiguous->conversation, $ambiguous),
            );

            $this->assertFalse($result->success);
            $this->assertSame('ambiguous', $result->payload['error'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_workspace_reports_index_lists_owned_cards(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на завтра',
                'report_type' => 'tomorrow_plan',
                'period_mode' => 'tomorrow',
                'local_time' => '22:00',
                'sources' => ['tasks', ['type' => 'google_calendar', 'calendar_scope' => 'all_relevant', 'calendar_names' => ['Семья']]],
            ]);

            $response = $this->actingAs($user)->getJson(route('jarvis.reports.index'));
            $response->assertOk();
            $response->assertJsonPath('items.0.name', 'Планы на завтра');
            $this->assertStringContainsString('22:00', (string) $response->json('items.0.schedule_label'));
            $this->assertContains('Задачи', $response->json('items.0.source_labels'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function truncatedReportSynthesizer(): SynthesizesProductivityBrief
    {
        return new class implements SynthesizesProductivityBrief
        {
            public function synthesize(User $user, string $mode, string $deterministic, array $sources): ?string
            {
                return 'Доброе утро. Сводка на сегодня,';
            }
        };
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner, 'timezone' => 'Europe/Rome'])->save();

        return $user;
    }

    private function connectGoogle(User $owner): void
    {
        $accounts = app(IntegrationAccountService::class);
        $account = $accounts->upsertAccount(
            $owner,
            'google',
            'google-sub-'.$owner->id,
            'owner@example.test',
            IntegrationAccountStatus::Connected,
            ['email', 'openid', 'profile', 'https://www.googleapis.com/auth/gmail.readonly'],
        );
        $accounts->markConnected($account);
    }

    private function inbound(User $user, string $body, ?int $conversationId = null): Message
    {
        $conversation = $conversationId !== null
            ? $user->conversations()->whereKey($conversationId)->firstOrFail()
            : app(ConversationService::class)->createPersonal($user, 'Основной');

        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'body' => $body,
            'message_type' => MessageType::Text,
            'occurred_at' => now(),
        ]);
    }
}
