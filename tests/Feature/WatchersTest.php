<?php

namespace Tests\Feature;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeSourceType;
use App\Enums\UserRole;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherHealth;
use App\Enums\WatcherReactionStatus;
use App\Enums\WatcherStatus;
use App\Models\Conversation;
use App\Models\JarvisNotification;
use App\Models\KnowledgeEvent;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Conversations\ConversationService;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Productivity\ProactiveDispatchService;
use App\Services\Reminders\ReminderToolPrompt;
use App\Services\Tasks\TaskService;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Watchers\Contracts\CalendarWatcherClient;
use App\Services\Watchers\Contracts\GitHubWatcherClient;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\WatcherDispatchService;
use App\Services\Watchers\WatcherEvaluationService;
use App\Services\Watchers\WatcherService;
use App\Services\Watchers\WatcherToolPrompt;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\FakeCalendarWatcherClient;
use Tests\Support\FakeGitHubWatcherClient;
use Tests\Support\FakeGmailWatcherClient;
use Tests\TestCase;

class WatchersTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    private FakeGmailWatcherClient $gmail;

    private FakeCalendarWatcherClient $calendar;

    private FakeGitHubWatcherClient $github;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->gmail = new FakeGmailWatcherClient;
        $this->calendar = new FakeCalendarWatcherClient;
        $this->github = new FakeGitHubWatcherClient;
        $this->app->instance(GmailWatcherClient::class, $this->gmail);
        $this->app->instance(CalendarWatcherClient::class, $this->calendar);
        $this->app->instance(GitHubWatcherClient::class, $this->github);
        $this->app->instance(AiChatGateway::class, new FakeAiChatGateway);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_schema_and_tools_exist(): void
    {
        $this->assertTrue(Schema::hasTable('watchers'));
        $this->assertTrue(Schema::hasTable('watcher_occurrences'));

        $user = null;
        try {
            $user = $this->createTemporaryUser();
            $names = array_map(
                static fn ($tool): string => $tool->name,
                app(ToolRegistry::class)->definitionsFor(new ToolExecutionContext($user, $this->chat($user))),
            );
            $this->assertContains(CreateWatcherTool::NAME, $names);
            $this->assertContains('list_watchers', $names);
            $this->assertContains('run_watcher_now', $names);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_explicit_creation_and_foreign_refs_denied(): void
    {
        $user = null;
        $other = null;
        try {
            $user = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $foreign = app(TaskService::class)->create($other, 'Foreign task');

            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());

            $owned = app(TaskService::class)->create($user, 'Own task');
            $watcher = $this->createTaskWatcher($user, $owned);
            $this->assertSame(WatcherStatus::Active, $watcher->status);

            try {
                app(WatcherService::class)->create($user, [
                    'name' => 'Steal',
                    'trigger_type' => 'task_state',
                    'condition_type' => 'overdue_by',
                    'task_id' => $foreign->id,
                    'cooldown_seconds' => 0,
                    'aggregation_window_seconds' => 0,
                ]);
                $this->fail('Foreign task watcher must be denied.');
            } catch (WatcherException $exception) {
                $this->assertSame('not_found', $exception->error);
            }
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_gmail_baseline_then_new_message_fires_once(): void
    {
        $user = null;
        try {
            $user = $this->owner();
            $this->gmail->messages = [[
                'id' => 'old-1',
                'thread_id' => 't-1',
                'sender' => 'admin@client.com',
                'subject' => 'Old',
            ]];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Apple reply',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'mode' => 'one_shot',
                'source' => ['sender' => 'admin@client.com'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $this->gmail->messages[] = [
                'id' => 'new-1',
                'thread_id' => 't-1',
                'sender' => 'admin@client.com',
                'subject' => 'Reply',
            ];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(WatcherStatus::Completed, $watcher->fresh()->status);

            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertNotNull(JarvisNotification::query()->where('user_id', $user->id)->where('source_type', 'watcher')->first());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_github_and_calendar_fakes(): void
    {
        $user = null;
        try {
            $user = $this->owner();
            $this->github->commits = [['sha' => 'aaa', 'message' => 'old', 'timestamp' => now()->toIso8601String()]];
            $commits = app(WatcherService::class)->create($user, [
                'name' => 'YFS commits',
                'trigger_type' => 'github_event',
                'condition_type' => 'github_new_commit',
                'mode' => 'recurring',
                'source' => ['repository' => 'Owiiiii1/YFS'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $commits->id)->count());
            $this->github->commits[] = ['sha' => 'bbb', 'message' => 'feat', 'timestamp' => now()->toIso8601String()];
            app(WatcherEvaluationService::class)->evaluate($commits->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $commits->id)->count());
            $this->assertSame(WatcherStatus::Active, $commits->fresh()->status);

            $this->calendar->events = [['id' => 'evt-1', 'title' => 'Marco', 'etag' => 'v1', 'start' => now()->toIso8601String(), 'status' => 'confirmed']];
            $cal = app(WatcherService::class)->create($user, [
                'name' => 'Marco moved',
                'trigger_type' => 'calendar_event',
                'condition_type' => 'calendar_changed',
                'mode' => 'recurring',
                'source' => ['event_id' => 'evt-1'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $cal->id)->count());
            $this->calendar->events = [['id' => 'evt-1', 'title' => 'Marco', 'etag' => 'v2', 'start' => now()->addHour()->toIso8601String(), 'status' => 'confirmed']];
            app(WatcherEvaluationService::class)->evaluate($cal->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $cal->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_task_deadline_cooldown_pause_cancel_and_update_baseline(): void
    {
        $user = null;
        try {
            $user = $this->createTemporaryUser();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
            $task = app(TaskService::class)->create($user, 'Report', dueAt: CarbonImmutable::parse('2026-09-03 09:00:00', 'UTC'));
            $watcher = $this->createTaskWatcher($user, $task, [
                'condition_type' => 'overdue_by',
                'condition' => ['hours' => 24],
                'mode' => 'recurring',
            ]);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-04 10:00:00', 'UTC'));
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $paused = app(WatcherService::class)->pauseOwned($user, (int) $watcher->id);
            $this->assertSame(WatcherStatus::Paused, $paused->status);
            app(WatcherEvaluationService::class)->evaluate($paused->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $cancelled = app(WatcherService::class)->cancelOwned($user, (int) $watcher->id);
            $this->assertSame(WatcherStatus::Cancelled, $cancelled->status);
            $this->assertSame(0, app(WatcherDispatchService::class)->dispatchDue(40));
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_cooldown_suppresses_and_fingerprint_is_unique(): void
    {
        $user = null;
        try {
            $user = $this->owner();
            $this->gmail->messages = [];
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Inbox',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'mode' => 'recurring',
                'source' => ['query' => 'from:admin@client.com'],
                'cooldown_seconds' => 3600,
                'aggregation_window_seconds' => 0,
            ]);
            $this->gmail->messages = [['id' => 'm1', 'thread_id' => 't', 'sender' => 'admin@client.com', 'subject' => 'A']];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->where('status', '!=', 'suppressed')->count());

            $this->gmail->messages[] = ['id' => 'm2', 'thread_id' => 't', 'sender' => 'admin@client.com', 'subject' => 'B'];
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertTrue(WatcherOccurrence::query()->where('watcher_id', $watcher->id)->where('matched_condition', 'cooldown')->exists());

            $first = WatcherOccurrence::query()->where('watcher_id', $watcher->id)->where('status', 'matched')->first();
            $this->expectException(QueryException::class);
            WatcherOccurrence::query()->create([
                'watcher_id' => $watcher->id,
                'user_id' => $user->id,
                'trigger_fingerprint' => $first->trigger_fingerprint,
                'detected_at' => now(),
                'status' => 'matched',
                'matched_condition' => 'new_item',
                'reaction_status' => 'pending',
            ]);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_knowledge_event_triggers_local_watcher_without_duplicates(): void
    {
        $user = null;
        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $entity = app(KnowledgeIngestionService::class)->upsertEntity(
                $user,
                KnowledgeEntityType::Project,
                'YFS',
                $this->source($conversation, 'ent'),
            );
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'YFS events',
                'trigger_type' => 'knowledge_event',
                'condition_type' => 'entity_event_type',
                'mode' => 'recurring',
                'knowledge_entity_id' => $entity->id,
                'source' => ['event_type' => 'github_commit_seen'],
                'condition' => ['event_type' => 'github_commit_seen'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            $ingestion = app(KnowledgeIngestionService::class);
            $eventSource = $this->source($conversation, 'commit-1');
            $ingestion->recordEvent($user, KnowledgeEventType::GithubCommitSeen, 'commit aaa', $eventSource, [$entity]);
            $ingestion->recordEvent($user, KnowledgeEventType::GithubCommitSeen, 'commit aaa', $eventSource, [$entity]);

            $this->assertSame(1, KnowledgeEvent::query()->where('user_id', $user->id)->where('type', KnowledgeEventType::GithubCommitSeen)->count());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_auth_blocks_without_hammering_and_transient_backs_off(): void
    {
        $user = null;
        try {
            $user = $this->owner();
            $this->gmail->exception = new IntegrationException('google_not_connected', 'Gmail is not connected.');
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Mail',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'source' => ['query' => 'from:a@b.com'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->assertSame(1, $this->gmail->searchCalls);
            $this->assertSame(WatcherHealth::Blocked, $watcher->fresh()->health);
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, $this->gmail->searchCalls);
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('dedupe_key', 'watcher-blocked:'.$watcher->id)->count());

            $this->gmail->exception = new IntegrationException('timeout', 'timed out', retryable: true);
            $this->github->commits = [];
            $git = app(WatcherService::class)->create($user, [
                'name' => 'Repo',
                'trigger_type' => 'github_event',
                'condition_type' => 'github_new_commit',
                'source' => ['repository' => 'Owiiiii1/JARVIS'],
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->github->exception = new IntegrationException('timeout', 'timed out', retryable: true);
            app(WatcherEvaluationService::class)->evaluate($git->fresh());
            $calls = $this->github->commitCalls;
            app(WatcherEvaluationService::class)->evaluate($git->fresh());
            $this->assertSame($calls, $this->github->commitCalls);
            $this->assertSame(WatcherHealth::Waiting, $git->fresh()->health);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_reactions_notifications_proposed_external_and_idempotent_internal(): void
    {
        $user = null;
        try {
            $user = $this->createTemporaryUser();
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00', 'UTC'));
            $task = app(TaskService::class)->create($user, 'Open work', dueAt: CarbonImmutable::parse('2026-09-04 09:00:00', 'UTC'));
            $beforeTasks = Task::query()->where('user_id', $user->id)->count();
            $watcher = $this->createTaskWatcher($user, $task, [
                'condition_type' => 'deadline_within',
                'condition' => ['hours' => 24],
                'reaction_type' => 'create_task',
                'reaction_config' => ['title' => 'Follow up'],
                'mode' => 'one_shot',
            ]);
            CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-03 20:00:00', 'UTC'));
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame($beforeTasks + 1, Task::query()->where('user_id', $user->id)->count());
            $this->assertTrue(Task::query()->where('user_id', $user->id)->where('title', 'Follow up')->exists());

            $owner = $user;
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $this->gmail->messages = [];
            $proposed = app(WatcherService::class)->create($owner, [
                'name' => 'Send contract',
                'trigger_type' => 'gmail_message',
                'condition_type' => 'new_item',
                'reaction_type' => 'propose_action',
                'reaction_config' => ['tool' => 'send_gmail_message'],
                'source' => ['query' => 'from:client@example.com'],
                'mode' => 'one_shot',
                'cooldown_seconds' => 0,
                'aggregation_window_seconds' => 0,
            ]);
            $this->gmail->messages = [['id' => 'yes-1', 'thread_id' => 'c', 'sender' => 'client@example.com', 'subject' => 'Yes']];
            app(WatcherEvaluationService::class)->evaluate($proposed->fresh());
            $occurrence = WatcherOccurrence::query()->where('watcher_id', $proposed->id)->first();
            $this->assertSame(WatcherReactionStatus::Proposed, $occurrence?->reaction_status);
            $notification = JarvisNotification::query()->where('user_id', $owner->id)->where('source_id', $proposed->id)->latest('id')->first();
            $this->assertSame('needs_confirmation', $notification?->metadata['pending_action']['status'] ?? null);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_http_scope_scheduler_prune_and_guidance(): void
    {
        $user = null;
        $other = null;
        try {
            $user = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Scoped');
            $watcher = $this->createTaskWatcher($user, $task);

            $this->actingAs($user)->getJson(route('jarvis.watchers.index'))->assertOk()->assertJsonPath('active_count', 1);
            $this->actingAs($other)->getJson(route('jarvis.watchers.show', $watcher->id))->assertNotFound();

            $watcher->forceFill(['next_check_at' => CarbonImmutable::now('UTC')->subMinute()])->save();
            $this->assertSame(1, app(WatcherDispatchService::class)->dispatchDue(40));
            $this->assertSame(0, app(WatcherDispatchService::class)->dispatchDue(40));

            WatcherOccurrence::query()->create([
                'watcher_id' => $watcher->id,
                'user_id' => $user->id,
                'trigger_fingerprint' => hash('sha256', 'old-prune'),
                'detected_at' => CarbonImmutable::now('UTC')->subDays(120),
                'status' => 'matched',
                'matched_condition' => 'new_item',
                'reaction_status' => 'executed',
            ]);
            Artisan::call('jarvis:watchers:prune');
            $this->assertTrue(WatcherOccurrence::query()->where('trigger_fingerprint', hash('sha256', 'old-prune'))->exists());

            $guidance = implode("\n", array_merge(ReminderToolPrompt::lines(), WatcherToolPrompt::lines()));
            $this->assertStringContainsString('watcher', $guidance);
            $this->assertStringContainsString('create_watcher', $guidance);
            $this->assertStringContainsString('still_open', $guidance);
            $this->assertStringContainsString('hours=24', $guidance);
            $this->assertStringContainsString('проверяй каждое утро почту', $guidance);
            $this->assertStringContainsString('Jarvis-performed check', $guidance);
            $this->assertStringContainsString('ProactiveDispatchService', file_get_contents(base_path('app/Services/Productivity/ProactiveDispatchService.php')));
            $this->assertStringNotContainsString('ProactiveDispatchService', file_get_contents(base_path('app/Services/Watchers/WatcherEvaluationService.php')));
            $this->assertTrue(class_exists(ProactiveDispatchService::class));

            $beforeUpdate = WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count();
            $updated = app(WatcherService::class)->updateOwned($user, (int) $watcher->id, [
                'condition' => ['hours' => 48],
            ]);
            $this->assertTrue($updated->fresh()->baselineEstablished());
            $this->assertSame($beforeUpdate, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_creating_a_task_does_not_invent_a_watcher(): void
    {
        $user = null;
        try {
            $user = $this->createTemporaryUser();
            app(TaskService::class)->create($user, 'Just a task');
            $this->assertSame(0, Watcher::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_status_equals_with_hours_does_not_match_until_the_delay_passes(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $now = CarbonImmutable::parse('2026-09-07 00:43:00', 'UTC');
            CarbonImmutable::setTestNow($now);
            $task = app(TaskService::class)->create($user, 'VC2 проверить новый билд');
            $watcher = $this->createTaskWatcher($user, $task, [
                'condition_type' => 'still_open',
                'condition' => ['hours' => 24],
                'mode' => 'one_shot',
            ]);

            $this->assertSame(WatcherConditionType::StatusEquals, $watcher->condition_type);
            $this->assertSame(24, (int) ($watcher->condition_config['hours'] ?? 0));
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(0, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());

            CarbonImmutable::setTestNow($now->addHours(24));
            app(WatcherEvaluationService::class)->evaluate($watcher->fresh());
            $this->assertSame(1, WatcherOccurrence::query()->where('watcher_id', $watcher->id)->count());
            $this->assertSame(WatcherStatus::Completed, $watcher->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_one_shot_task_watcher_is_resolved_when_the_task_is_closed(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Check the new build');
            $watcher = $this->createTaskWatcher($user, $task, [
                'condition_type' => 'status_equals',
                'condition' => ['status' => 'open'],
                'mode' => 'one_shot',
            ]);
            $this->assertSame(WatcherStatus::Active, $watcher->status);

            app(TaskService::class)->completeOwned($user, (int) $task->id);

            $watcher->refresh();
            $this->assertSame(WatcherStatus::Completed, $watcher->status);
            $this->assertSame('task_closed', $watcher->cursor['resolved_reason'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_status_changed_watcher_still_fires_on_task_completion(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Check the new build');
            $watcher = $this->createTaskWatcher($user, $task, [
                'condition_type' => 'status_changed',
                'mode' => 'one_shot',
            ]);

            app(TaskService::class)->completeOwned($user, (int) $task->id);

            $watcher->refresh();
            $this->assertNotNull($watcher->last_triggered_at);
            $this->assertArrayNotHasKey('resolved_reason', $watcher->cursor ?? []);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_watcher_rejects_an_integration_account_the_user_does_not_own(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Check the new build');

            try {
                $this->createTaskWatcher($user, $task, ['integration_account_id' => 999999999]);
                $this->fail('Foreign integration account must be denied.');
            } catch (WatcherException $exception) {
                $this->assertSame('not_found', $exception->error);
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function createTaskWatcher(User $user, Task $task, array $overrides = []): Watcher
    {
        return app(WatcherService::class)->create($user, array_merge([
            'name' => 'Watch '.$task->title,
            'trigger_type' => 'task_state',
            'condition_type' => 'overdue_by',
            'task_id' => $task->id,
            'cooldown_seconds' => 0,
            'aggregation_window_seconds' => 0,
        ], $overrides));
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function chat(User $user): Conversation
    {
        return app(ConversationService::class)->createPersonal($user, 'Основной');
    }

    private function source(Conversation $conversation, string $key): KnowledgeSourceRef
    {
        return new KnowledgeSourceRef(
            type: KnowledgeSourceType::Conversation,
            fingerprint: KnowledgeSourceRef::hash('watcher-test', (string) $conversation->id, $key),
            confidence: KnowledgeConfidence::deterministic(),
            conversationId: $conversation->id,
        );
    }
}
