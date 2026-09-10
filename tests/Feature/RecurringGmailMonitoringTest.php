<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\UserRole;
use App\Jobs\EvaluateWatcherJob;
use App\Models\AiRoleSetting;
use App\Models\IntegrationAccount;
use App\Models\JarvisNotification;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\Google\SearchGmailTool;
use App\Services\Tools\Reports\CreateScheduledReportTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherDigestRequest;
use App\Services\Watchers\WatcherEvaluationService;
use App\Services\Watchers\WatcherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\FakeGmailWatcherClient;
use Tests\TestCase;

class RecurringGmailMonitoringTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    private FakeGmailWatcherClient $gmail;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->gmail = new FakeGmailWatcherClient;
        $this->app->instance(GmailWatcherClient::class, $this->gmail);
        $this->app->instance(AiChatGateway::class, new FakeAiChatGateway);
    }

    public function test_morning_mail_check_creates_a_scheduled_report_not_a_reminder_or_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Проверяй каждое утро почту и сообщай мне, что нового пришло.');

            $reminder = app(CreateReminderTool::class)->execute(
                new ToolCall('c1', CreateReminderTool::NAME, [
                    'text' => 'проверить почту',
                    'run_at_local' => '2026-09-09T08:00:00+02:00',
                    'recurrence' => 'daily',
                ]),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );
            $watcher = app(CreateWatcherTool::class)->execute(
                new ToolCall('c2', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );
            $report = app(CreateScheduledReportTool::class)->execute(
                new ToolCall('c3', CreateScheduledReportTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($reminder->success);
            $this->assertSame('use_scheduled_report', $reminder->payload['error'] ?? null);
            $this->assertFalse($watcher->success);
            $this->assertSame('use_scheduled_report', $watcher->payload['error'] ?? null);
            $this->assertTrue($report->success);
            $this->assertSame(0, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
            $this->assertSame('mail_groups_digest', $report->payload['report_type'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_create_watcher_tool_does_not_hijack_a_morning_gmail_report(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Каждый день в 7:30 проверяй Gmail.');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('use_scheduled_report', $result->payload['error'] ?? null);
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_remind_me_to_check_mail_still_creates_a_reminder(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $inbound = $this->inbound($user, 'Напомни мне завтра проверить почту.');

            $result = app(CreateReminderTool::class)->execute(
                new ToolCall('c1', CreateReminderTool::NAME, [
                    'text' => 'проверить почту',
                    'run_at_local' => '2026-12-15T09:00:00+02:00',
                ]),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $this->assertArrayHasKey('reminder_id', $result->payload);
            $this->assertSame(1, Reminder::query()->where('user_id', $user->id)->count());
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_first_evaluation_baselines_history_and_later_checks_only_new_mail(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [
                ['id' => 'old-1', 'sender' => 'Ada <ada@test>', 'subject' => 'Yesterday'],
            ];
            $now = CarbonImmutable::parse('2026-09-08 08:05:00', 'Europe/Rome')->utc();
            CarbonImmutable::setTestNow($now);

            $watcher = app(WatcherService::class)->create($user, WatcherDigestRequest::gmailMorningFromInbound(
                'Проверяй каждое утро почту и рассказывай, что нового.',
                $user,
            ) ?? []);

            $this->assertTrue($watcher->fresh()->baselineEstablished());
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(0, JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->count());
            $this->assertSame(
                '2026-09-09 08:00',
                $watcher->fresh()->next_check_at?->setTimezone('Europe/Rome')->format('Y-m-d H:i'),
            );

            $this->gmail->messages[] = [
                'id' => 'new-1',
                'sender' => 'Marco Rossi <marco@yfs.test>',
                'subject' => 'New YFS design',
            ];
            $this->gmail->messages[] = [
                'id' => 'new-2',
                'sender' => 'GitHub <notifications@github.com>',
                'subject' => 'CI failed',
            ];

            $later = CarbonImmutable::parse('2026-09-09 08:00:00', 'Europe/Rome')->utc();
            CarbonImmutable::setTestNow($later);
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh(), force: true);

            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $notification = JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->first();
            $this->assertNotNull($notification);
            $this->assertStringContainsString('2 новых письма', (string) $notification->body);
            $this->assertStringContainsString('Marco Rossi', (string) $notification->body);
            $this->assertStringNotContainsString('old-1', (string) $notification->body);
            $this->assertStringNotContainsString('new-1', (string) $notification->body);
            $this->assertStringNotContainsString('gmail_message', (string) $notification->body);

            app(WatcherEvaluationService::class)->evaluate($watcher->fresh(), force: true);
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->count());
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_zero_mail_digest_notifies_once(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [];
            $now = CarbonImmutable::parse('2026-09-08 08:00:00', 'Europe/Rome')->utc();
            CarbonImmutable::setTestNow($now);
            $watcher = app(WatcherService::class)->create($user, WatcherDigestRequest::gmailMorningFromInbound(
                'Проверяй каждое утро почту и сообщай, что нового.',
                $user,
            ) ?? []);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $later = CarbonImmutable::parse('2026-09-09 08:00:00', 'Europe/Rome')->utc();
            CarbonImmutable::setTestNow($later);
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh(), force: true);

            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $body = (string) JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->value('body');
            $this->assertSame('С утра новых писем нет.', $body);

            app(WatcherEvaluationService::class)->evaluate($watcher->fresh(), force: true);
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_foreign_integration_account_is_rejected(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->owner();
            $other = $this->owner();
            $foreign = $this->connectGoogle($other);

            try {
                app(WatcherService::class)->create($user, array_merge(
                    WatcherDigestRequest::gmailMorningFromInbound('Проверяй каждое утро почту.', $user) ?? [],
                    ['integration_account_id' => $foreign->id],
                ));
                $this->fail('Foreign Gmail account must be denied.');
            } catch (WatcherException $exception) {
                $this->assertSame('not_found', $exception->error);
            }

            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_disconnected_gmail_asks_to_connect_without_creating_records(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $inbound = $this->inbound($user, 'Проверяй каждое утро почту и сообщай мне, что нового пришло.');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('use_scheduled_report', $result->payload['error'] ?? null);
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
            $this->assertSame(0, Reminder::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_missing_gmail_scope_asks_for_permission(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user, gmail: false);
            $inbound = $this->inbound($user, 'Проверяй каждое утро почту.');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('use_scheduled_report', $result->payload['error'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_prompt_knows_gmail_monitoring_exists(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $configuration = AiRoleSetting::query()
                ->where('role_key', AiRoleKey::UserConversation->value)
                ->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build(
                $user,
                $conversation,
                $configuration,
                tools: [new ToolDefinition(SearchGmailTool::NAME, 'Search Gmail.', [])],
            );

            $this->assertStringContainsString('Never say you have no Gmail monitoring', $context['system_prompt']);
            $this->assertStringContainsString('create_scheduled_report', $context['system_prompt']);
            $this->assertStringContainsString('create_watcher (gmail event)', $context['system_prompt']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

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

    private function connectGoogle(User $owner, bool $gmail = true): IntegrationAccount
    {
        $scopes = ['email', 'openid', 'profile'];
        if ($gmail) {
            $scopes[] = 'https://www.googleapis.com/auth/gmail.readonly';
        }

        $accounts = app(IntegrationAccountService::class);
        $account = $accounts->upsertAccount(
            $owner,
            'google',
            'google-sub-'.$owner->id,
            'owner@example.test',
            IntegrationAccountStatus::Connected,
            $scopes,
        );
        $accounts->markConnected($account);

        return $account->fresh() ?? $account;
    }
}
