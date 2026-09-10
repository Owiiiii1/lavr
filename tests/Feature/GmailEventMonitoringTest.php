<?php

namespace Tests\Feature;

use App\Enums\IntegrationAccountStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ReferenceOutcome;
use App\Enums\TopicContinuityMode;
use App\Enums\UserRole;
use App\Enums\WatcherMode;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use App\Jobs\EvaluateWatcherJob;
use App\Models\IntegrationAccount;
use App\Models\JarvisNotification;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\ConversationIntelligence\ConversationalEntity;
use App\Services\ConversationIntelligence\WorkingContext;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\WatcherEvaluationService;
use App\Services\Watchers\WatcherSchedule;
use App\Services\Watchers\WatcherService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\FakeGmailWatcherClient;
use Tests\TestCase;

class GmailEventMonitoringTest extends TestCase
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

    public function test_wait_for_school_mail_creates_a_gmail_event_watcher_not_digest_or_knowledge(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Жди письмо от школы');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, [
                    'trigger_type' => 'knowledge_event',
                    'condition_type' => 'entity_event_type',
                    'source' => ['sender_domains' => ['marcellinequadronno.it']],
                ]),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $this->assertSame('gmail_event', $result->payload['kind']);
            $watcher = Watcher::query()->where('user_id', $user->id)->sole();
            $this->assertSame(WatcherTriggerType::GmailMessage, $watcher->trigger_type);
            $this->assertSame(WatcherMode::Recurring, $watcher->mode);
            $this->assertFalse(WatcherSchedule::isDigest($watcher));
            $this->assertSame(['marcellinequadronno.it'], $watcher->source_config['sender_domains'] ?? null);
            $this->assertStringContainsString('marcellinequadronno.it', (string) $result->payload['description']);
            $this->assertStringNotContainsString('каждое утро', mb_strtolower((string) $result->payload['description']));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_morning_digest_phrase_is_a_scheduled_report_not_a_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Каждое утро дай сводку почты');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, [
                    'source' => ['sender_domains' => ['example.com']],
                ]),
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
            $inbound = $this->inbound($user, 'Напомни проверить почту');

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

    public function test_sender_email_creates_an_event_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Следи за письмами от Marco.');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, [
                    'source' => ['sender' => 'marco@client.com'],
                ]),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $watcher = Watcher::query()->where('user_id', $user->id)->sole();
            $this->assertSame(['marco@client.com'], $watcher->source_config['senders'] ?? null);
            $this->assertFalse(WatcherSchedule::isDigest($watcher));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_sender_domain_creates_an_event_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Следи за письмами от @example.com.');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $watcher = Watcher::query()->where('user_id', $user->id)->sole();
            $this->assertSame(['example.com'], $watcher->source_config['sender_domains'] ?? null);
            $this->assertSame(WatcherMode::Recurring, $watcher->mode);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_multiple_domains_create_one_or_filter_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound(
                $user,
                'Следи за письмами от marcellinequadronno.it и accademiaucraina.it и сообщай, когда они приходят.',
            );

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $this->assertSame(1, Watcher::query()->where('user_id', $user->id)->count());
            $watcher = Watcher::query()->where('user_id', $user->id)->sole();
            $this->assertSame(
                ['marcellinequadronno.it', 'accademiaucraina.it'],
                $watcher->source_config['sender_domains'] ?? null,
            );
            $this->assertStringContainsString('marcellinequadronno.it и accademiaucraina.it', (string) $result->payload['description']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_continuous_monitor_defaults_to_recurring(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Письма от school.it',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['sender_domains' => ['school.it']],
            ]);

            $this->assertSame(WatcherMode::Recurring, $watcher->mode);
            $this->assertSame(0, (int) $watcher->cooldown_seconds);
            $this->assertFalse(WatcherSchedule::isDigest($watcher));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_explicit_first_letter_is_one_shot(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Сообщи, когда придёт первое письмо от marco@example.com');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertTrue($result->success);
            $watcher = Watcher::query()->where('user_id', $user->id)->sole();
            $this->assertSame(WatcherMode::OneShot, $watcher->mode);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_invalid_gmail_config_does_not_fall_back_to_knowledge(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Жди письмо от школы');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, [
                    'trigger_type' => 'knowledge_event',
                    'condition_type' => 'entity_event_type',
                    'condition' => ['event_type' => 'new_email'],
                    'entity_name' => 'Танцевальная академия',
                ]),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('gmail_filter_required', $result->payload['error']);
            $this->assertSame('Не удалось создать мониторинг Gmail: нужен отправитель или домен.', $result->payload['message']);
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_failed_create_watcher_payload_is_not_success(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $inbound = $this->inbound($user, 'Жди письмо от школы');

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $inbound->conversation, $inbound),
            );

            $this->assertFalse($result->success);
            $this->assertSame('failed', $result->payload['kind'] ?? null);
            $this->assertArrayNotHasKey('watcher_id', $result->payload);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_follow_up_adds_a_domain_to_the_existing_gmail_watcher(): void
    {
        $user = null;

        try {
            Queue::fake([EvaluateWatcherJob::class]);
            $user = $this->owner();
            $this->connectGoogle($user);
            $first = $this->inbound($user, 'Следи за письмами от marcellinequadronno.it');
            $created = app(CreateWatcherTool::class)->execute(
                new ToolCall('c1', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $first->conversation, $first),
            );
            $this->assertTrue($created->success);
            $watcherId = (int) $created->payload['watcher_id'];

            $followUp = $this->inbound($user, 'И ещё следи за письмами от accademiaucraina.it.', $first->conversation_id);
            $working = new WorkingContext(
                topicMode: TopicContinuityMode::Continue,
                referenceOutcome: ReferenceOutcome::Resolved,
                continuitySource: 'recent_tool_results',
                recentToolReferences: [
                    new ConversationalEntity('watcher', 'Письма от marcellinequadronno.it', $watcherId, true),
                ],
            );

            $result = app(CreateWatcherTool::class)->execute(
                new ToolCall('c2', CreateWatcherTool::NAME, []),
                new ToolExecutionContext($user, $followUp->conversation, $followUp, working: $working),
            );

            $this->assertTrue($result->success);
            $this->assertTrue((bool) ($result->payload['updated'] ?? false));
            $this->assertSame(1, Watcher::query()->where('user_id', $user->id)->count());
            $watcher = Watcher::query()->find($watcherId);
            $this->assertNotNull($watcher);
            $this->assertSame(
                ['marcellinequadronno.it', 'accademiaucraina.it'],
                $watcher->source_config['sender_domains'] ?? null,
            );
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_existing_mail_is_baselined_and_a_later_message_notifies(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [[
                'id' => 'old-school',
                'sender' => 'Tutor <tutor@school.it>',
                'subject' => 'Already here',
            ]];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Письма от school.it',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['sender_domains' => ['school.it']],
            ]);

            $this->assertTrue($watcher->fresh()->baselineEstablished());
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $this->gmail->messages[] = [
                'id' => 'new-school',
                'sender' => 'Segreteria <info@school.it>',
                'subject' => 'New note',
            ];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());

            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(WatcherStatus::Active, $watcher->fresh()->status);
            $this->assertNotNull(JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->first());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_second_matching_message_also_notifies_a_recurring_watcher(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [['id' => 'old', 'sender' => 'a@school.it', 'subject' => 'Old']];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Письма от school.it',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['sender_domains' => ['school.it']],
            ]);

            $this->gmail->messages[] = ['id' => 'new-1', 'sender' => 'a@school.it', 'subject' => 'First new'];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->gmail->messages[] = ['id' => 'new-2', 'sender' => 'b@school.it', 'subject' => 'Second new'];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());

            $this->assertSame(2, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(WatcherStatus::Active, $watcher->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_duplicate_message_is_not_renotified(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [['id' => 'old', 'sender' => 'a@school.it', 'subject' => 'Old']];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Письма от school.it',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['sender_domains' => ['school.it']],
            ]);
            $this->gmail->messages[] = ['id' => 'new-1', 'sender' => 'a@school.it', 'subject' => 'New'];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());

            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_unrelated_sender_does_not_trigger(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $this->gmail->messages = [['id' => 'old', 'sender' => 'a@school.it', 'subject' => 'Old']];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Письма от school.it',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['sender_domains' => ['school.it']],
            ]);
            $this->gmail->messages[] = ['id' => 'stripe', 'sender' => 'Stripe <no-reply@stripe.com>', 'subject' => 'Receipt'];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());

            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
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
