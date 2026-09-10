<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Models\AutomationRun;
use App\Models\Commitment;
use App\Models\JarvisNotification;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Automation\StaleAutomationRunRecovery;
use App\Services\Commitments\CommitmentNotifier;
use App\Services\Conversations\ConversationService;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Notifications\NotificationUrlPolicy;
use App\Services\Reports\ScheduledReportCollector;
use App\Services\Reports\ScheduledReportComposer;
use App\Services\Reports\ScheduledReportDispatchService;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Reports\CreateScheduledReportTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\Watchers\CreateWatcherTool;
use Carbon\CarbonImmutable;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_periodic_digest_cannot_create_a_gmail_digest_watcher(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $inbound = $this->inbound($user, 'Каждое утро проверь почту');
            $context = new ToolExecutionContext($user, $inbound->conversation, $inbound);

            $watcher = app(CreateWatcherTool::class)->execute(new ToolCall('w1', CreateWatcherTool::NAME, []), $context);
            $reminder = app(CreateReminderTool::class)->execute(new ToolCall('r1', CreateReminderTool::NAME, [
                'text' => 'проверить почту',
                'run_at_local' => '2026-09-10T08:00:00+02:00',
            ]), $context);
            $report = app(CreateScheduledReportTool::class)->execute(new ToolCall('s1', CreateScheduledReportTool::NAME, []), $context);

            $this->assertFalse($watcher->success);
            $this->assertSame('use_scheduled_report', $watcher->payload['error'] ?? null);
            $this->assertFalse($reminder->success);
            $this->assertSame('use_scheduled_report', $reminder->payload['error'] ?? null);
            $this->assertTrue($report->success);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_ambiguous_digest_and_condition_asks_once(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $inbound = $this->inbound($user, 'Каждое утро дай сводку почты и следи когда придёт файл');
            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('clarify_intent', $result->payload['error'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_report_run_is_recorded_once_and_ai_disabled_still_delivers(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-10 06:00:00', 'UTC'));
            $report = app(ScheduledReportService::class)->create($user, [
                'name' => 'Планы на сегодня',
                'report_type' => 'daily_plan',
                'period_mode' => 'today',
                'local_time' => '08:00',
                'sources' => ['tasks', 'commitments', 'google_calendar'],
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
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'scheduled_report')->count());
            $this->assertSame(1, AutomationRun::query()->where('user_id', $user->id)->where('automation_type', AutomationType::ScheduledReport)->count());
            $run = AutomationRun::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($run);
            $this->assertContains($run->status, [AutomationRunOutcome::Success, AutomationRunOutcome::Partial]);
            $this->assertSame('scheduled_report_v1', $run->metrics['prompt_version'] ?? null);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_commitment_overdue_notifies_once(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $commitment = Commitment::factory()->create([
                'user_id' => $user->id,
                'title' => 'Бюджет',
                'person_name_raw' => 'Сергій',
                'status' => CommitmentEffectiveStatus::Overdue,
                'lifecycle_status' => CommitmentLifecycleStatus::Open,
                'deadline_at' => CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'),
            ]);

            $notifier = app(CommitmentNotifier::class);
            $notifier->notifyStatusTransition($commitment->fresh(), CommitmentEffectiveStatus::Overdue);
            $notifier->notifyStatusTransition($commitment->fresh(), CommitmentEffectiveStatus::Overdue);

            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'commitment')->count());
            $this->assertSame(1, AutomationRun::query()->where('user_id', $user->id)->where('automation_type', AutomationType::Commitment)->count());
            $this->assertStringContainsString('Нагадати', (string) JarvisNotification::query()->where('user_id', $user->id)->value('body'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_stale_processing_run_is_recovered(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $run = AutomationRun::query()->create([
                'user_id' => $user->id,
                'automation_type' => AutomationType::ScheduledReport,
                'automation_id' => 1,
                'run_key' => 'scheduled_report:1:stale-test',
                'status' => AutomationRunOutcome::Processing,
                'attempt' => 1,
                'started_at' => CarbonImmutable::now('UTC')->subMinutes(45),
            ]);

            $count = (new StaleAutomationRunRecovery)->recover(30, true);

            $this->assertGreaterThanOrEqual(1, $count);
            $this->assertSame(AutomationRunOutcome::Retryable, $run->fresh()->status);
            $this->assertSame('failed_stale', $run->fresh()->outcome_code);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner, 'timezone' => 'Europe/Rome'])->save();

        return $user;
    }

    private function inbound(User $user, string $body): Message
    {
        $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');

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
