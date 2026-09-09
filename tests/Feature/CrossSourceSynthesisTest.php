<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\JarvisNotificationType;
use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationType;
use App\Enums\KnowledgeSourceType;
use App\Enums\ProductivityBriefMode;
use App\Enums\ReminderStatus;
use App\Enums\SynthesisType;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Enums\WatcherMode;
use App\Enums\WatcherStatus;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\JarvisNotification;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Context\ContextBudgetManager;
use App\Services\Context\ContextSlices;
use App\Services\Conversations\ConversationService;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Productivity\ProactiveDispatchService;
use App\Services\Productivity\ProductivityBriefCollector;
use App\Services\Productivity\ProductivityBriefRenderer;
use App\Services\Projects\ProjectService;
use App\Services\Reminders\ReminderService;
use App\Services\Synthesis\CommitmentLanguage;
use App\Services\Synthesis\CrossSourceSynthesisService;
use App\Services\Synthesis\DTO\SynthesisScope;
use App\Services\Synthesis\SynthesisCache;
use App\Services\Synthesis\SynthesisFactCollector;
use App\Services\Synthesis\SynthesisNarrativeService;
use App\Services\Tasks\TaskService;
use App\Services\Tools\Synthesis\GetPersonStatusTool;
use App\Services\Tools\Synthesis\GetProjectStatusTool;
use App\Services\Tools\Synthesis\GetSynthesisTool;
use App\Services\Tools\Synthesis\ListCommitmentsTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use App\Services\Watchers\WatcherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ReflectionClass;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\TestCase;

class CrossSourceSynthesisTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->app->instance(AiChatGateway::class, new FakeAiChatGateway);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_tools_registered_and_regular_user_cannot_use_project_status(): void
    {
        $user = null;
        $owner = null;

        try {
            $user = $this->createTemporaryUser();
            $owner = $this->owner();
            $regular = array_map(
                static fn ($tool): string => $tool->name,
                app(ToolRegistry::class)->definitionsFor(new ToolExecutionContext($user, $this->chat($user))),
            );
            $owned = array_map(
                static fn ($tool): string => $tool->name,
                app(ToolRegistry::class)->definitionsFor(new ToolExecutionContext($owner, $this->chat($owner))),
            );

            $this->assertContains(GetSynthesisTool::NAME, $regular);
            $this->assertContains(GetPersonStatusTool::NAME, $regular);
            $this->assertContains(ListCommitmentsTool::NAME, $regular);
            $this->assertNotContains(GetProjectStatusTool::NAME, $regular);
            $this->assertContains(GetProjectStatusTool::NAME, $owned);
            $this->assertFalse(class_exists(SynthesisFactCollector::class) && $this->collectorPollsIntegrations());
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($owner);
        }
    }

    public function test_project_synthesis_combines_task_knowledge_watcher_and_dedupes(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $project = app(ProjectService::class)->create($user, 'YFS');
            $task = app(TaskService::class)->create($user, 'Ship YFS demo', projectId: $project->id);
            $conversation = $this->chat($user);
            $entity = $this->ingest($user, KnowledgeEntityType::Project, 'YFS', $conversation, 'yfs-e', ['project_id' => $project->id]);
            $fingerprint = KnowledgeSourceRef::hash('github', 'sha-abc');
            $event = app(KnowledgeIngestionService::class)->recordEvent(
                $user,
                KnowledgeEventType::GithubCommitSeen,
                'Commit abc on YFS',
                new KnowledgeSourceRef(
                    type: KnowledgeSourceType::Github,
                    fingerprint: $fingerprint,
                    confidence: KnowledgeConfidence::manual(),
                    projectId: $project->id,
                    observedAt: CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC'),
                ),
                [$entity],
            );
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'When Apple replies',
                'trigger_type' => 'knowledge_event',
                'condition_type' => 'entity_event_type',
                'mode' => 'one_shot',
                'knowledge_entity_id' => $entity->id,
                'project_id' => $project->id,
            ]);
            WatcherOccurrence::query()->create([
                'watcher_id' => $watcher->id,
                'user_id' => $user->id,
                'trigger_fingerprint' => $fingerprint,
                'detected_at' => CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC'),
                'status' => 'matched',
                'knowledge_event_id' => $event->id,
                'metadata' => ['source_fingerprint' => $fingerprint],
            ]);

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::ProjectStatus,
                projectId: $project->id,
                withNarrative: false,
                skipCache: true,
            ));

            $titles = array_map(static fn ($item) => $item->title, $result->openWork);
            $this->assertContains('Ship YFS demo', $titles);
            $this->assertNotSame([], $result->waitingFor);
            $this->assertNotSame([], $result->sources);
            $changes = array_values(array_filter(
                $result->recentChanges,
                static fn ($item): bool => str_contains($item->title, 'Commit abc') || str_contains($item->title, 'автоматизация'),
            ));
            $this->assertCount(1, $changes);
            $this->assertArrayHasKey('generated_from', $result->freshness);
            $this->assertSame('indexed_domains', $result->freshness['generated_from']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_waiting_for_one_shot_and_resolves_when_completed(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Wait on reply');
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Apple reply',
                'trigger_type' => 'task_state',
                'condition_type' => 'overdue_by',
                'mode' => 'one_shot',
                'task_id' => $task->id,
            ]);
            $this->assertSame(WatcherMode::OneShot, $watcher->mode);

            $waiting = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::WaitingFor,
                withNarrative: false,
                skipCache: true,
            ));
            // The card names the work we are waiting on, not the internal watcher name.
            $this->assertTrue(collect($waiting->waitingFor)->contains(
                fn ($item) => str_contains($item->title, 'Wait on reply') && ! str_contains($item->title, 'overdue_by'),
            ));

            $watcher->forceFill(['status' => WatcherStatus::Completed])->save();
            app(SynthesisCache::class)->bump($user);

            $after = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::WaitingFor,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertFalse(collect($after->waitingFor)->contains(fn ($item) => str_contains($item->title, 'Wait on reply')));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_waiting_for_ignores_a_watcher_whose_task_is_already_closed(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $task = app(TaskService::class)->create($user, 'Check the new build');
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Build still open',
                'trigger_type' => 'task_state',
                'condition_type' => 'overdue_by',
                'mode' => 'one_shot',
                'task_id' => $task->id,
            ]);

            // A watcher left Active by an earlier release, on a task that is already closed.
            Task::query()->whereKey($task->id)->update(['status' => TaskStatus::Completed]);
            $watcher->forceFill(['status' => WatcherStatus::Active])->save();

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::WaitingFor,
                withNarrative: false,
                skipCache: true,
            ));

            $references = static fn (array $items): bool => collect($items)->contains(
                fn ($item) => collect($item->sources)->contains(fn ($source) => (int) $source->watcherId === (int) $watcher->id),
            );

            $this->assertFalse($references($result->waitingFor));
            $this->assertFalse($references($result->openLoops));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_overdue_and_external_dependency_blockers(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $parent = app(TaskService::class)->create($user, 'Prep');
            $child = app(TaskService::class)->create(
                $user,
                'Overdue child',
                dueAt: CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC'),
                parentTaskId: $parent->id,
            );
            $external = app(TaskService::class)->create($user, 'Wait on vendor');
            $external->forceFill(['metadata' => ['external_dependency' => true, 'waiting_for' => 'Vendor invoice']])->save();

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::Blockers,
                withNarrative: false,
                skipCache: true,
            ));

            $titles = array_map(static fn ($item) => $item->title, $result->blockers);
            $this->assertContains('Overdue child', $titles);
            $waiting = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::WaitingFor,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertTrue(collect($waiting->waitingFor)->contains(fn ($item) => str_contains($item->title, 'Vendor')));
            unset($child);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_explicit_commitment_not_vague_and_fulfill_only_with_task_link(): void
    {
        $user = null;

        try {
            $this->assertTrue(CommitmentLanguage::isExplicit('я пришлю до пятницы'));
            $this->assertFalse(CommitmentLanguage::isExplicit('надо бы сделать дизайн'));

            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);
            $task = app(TaskService::class)->create($user, 'Send design');
            $ingestion->recordEvent(
                $user,
                KnowledgeEventType::CommitmentMade,
                'я пришлю до пятницы',
                $this->source($conversation, 'c-mine'),
                [],
                metadata: ['explicit' => true, 'side' => 'mine', 'status' => 'open', 'task_id' => $task->id],
            );
            $ingestion->recordEvent(
                $user,
                KnowledgeEventType::CommitmentMade,
                'Marco обещал прислать дизайн',
                $this->source($conversation, 'c-other'),
                [],
                metadata: ['explicit' => true, 'side' => 'others', 'status' => 'open'],
            );
            $skipped = $ingestion->applyExtraction($user, [
                'entities' => [],
                'relationships' => [],
                'events' => [[
                    'type' => 'commitment_made',
                    'title' => 'надо бы сделать дизайн',
                    'kind' => 'explicit',
                    'confidence' => 0.9,
                    'confidence_label' => 'high',
                ]],
            ], $this->source($conversation, 'c-vague'));
            $this->assertSame(0, $skipped['events']);

            $mine = app(ListCommitmentsTool::class)->execute(
                new ToolCall('c1', ListCommitmentsTool::NAME, ['mode' => 'mine']),
                new ToolExecutionContext($user, $conversation),
            );
            $this->assertTrue($mine->success);
            $this->assertCount(1, $mine->payload['commitments']);

            app(TaskService::class)->completeOwned($user, $task->id);
            $after = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::Commitments,
                commitmentMode: 'mine',
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertSame([], $after->commitments);

            $others = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::Commitments,
                commitmentMode: 'others',
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertCount(1, $others->commitments);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_person_own_graph_foreign_denied_and_unknown_activity(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $marco = $this->ingest($user, KnowledgeEntityType::Person, 'Marco', $conversation, 'marco-1');
            $foreign = $this->ingest($other, KnowledgeEntityType::Person, 'Secret', $this->chat($other), 'secret-1');

            $ok = app(GetPersonStatusTool::class)->execute(
                new ToolCall('p1', GetPersonStatusTool::NAME, ['entity_id' => $marco->id]),
                new ToolExecutionContext($user, $conversation),
            );
            $this->assertTrue($ok->success);
            $this->assertSame('unknown', $ok->payload['person']['last_activity']['kind']);

            $denied = app(GetPersonStatusTool::class)->execute(
                new ToolCall('p2', GetPersonStatusTool::NAME, ['entity_id' => $foreign->id]),
                new ToolExecutionContext($user, $conversation),
            );
            $this->assertFalse($denied->success);
            $this->assertSame('not_found', $denied->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_owner_project_excluded_for_regular_user(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->owner();
            $user = $this->createTemporaryUser();
            app(ProjectService::class)->create($owner, 'SecretOwnerProject');

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
                skipCache: true,
            ));
            $blob = json_encode($result->toArray()) ?: '';
            $this->assertStringNotContainsString('SecretOwnerProject', $blob);
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_task_authority_beats_stale_knowledge_and_unresolved_conflict_is_surfaced(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $task = app(TaskService::class)->create($user, 'Still open');
            app(KnowledgeIngestionService::class)->recordEvent(
                $user,
                KnowledgeEventType::TaskCompleted,
                'Completed Still open',
                $this->source($conversation, 'stale-complete'),
                [],
                metadata: ['task_id' => $task->id],
            );
            $withAuthority = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertNotSame([], $withAuthority->conflicts, json_encode($withAuthority->toArray()));
            $this->assertTrue(collect($withAuthority->openWork)->contains(fn ($item) => $item->title === 'Still open'));

            app(KnowledgeIngestionService::class)->recordEvent(
                $user,
                KnowledgeEventType::ManualNote,
                'Apple approved',
                $this->source($conversation, 'claim-a'),
                [],
                metadata: ['claim_key' => 'apple_decision', 'claim_value' => 'approved'],
            );
            app(KnowledgeIngestionService::class)->recordEvent(
                $user,
                KnowledgeEventType::ManualNote,
                'Apple rejected',
                $this->source($conversation, 'claim-b'),
                [],
                metadata: ['claim_key' => 'apple_decision', 'claim_value' => 'rejected'],
            );
            $conflicted = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::RecentChanges,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertTrue(collect($conflicted->conflicts)->contains(
                fn ($row) => array_key_exists('authority', $row) && $row['authority'] === null,
            ));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_freshness_stale_window_timezone_and_archived_not_stale(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $conversation = $this->chat($user);
            $project = app(ProjectService::class)->create($user, 'LiveGit');
            $entity = $this->ingest($user, KnowledgeEntityType::Project, 'LiveGit', $conversation, 'git-e', ['project_id' => $project->id]);
            app(KnowledgeIngestionService::class)->recordEvent(
                $user,
                KnowledgeEventType::GithubCommitSeen,
                'Old commit',
                new KnowledgeSourceRef(
                    type: KnowledgeSourceType::Github,
                    fingerprint: KnowledgeSourceRef::hash('github', 'old'),
                    confidence: KnowledgeConfidence::manual(),
                    projectId: $project->id,
                    observedAt: CarbonImmutable::parse('2026-09-05 01:00:00', 'UTC'),
                ),
                [$entity],
            );

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::RecentChanges,
                windowDays: 7,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertTrue(collect($result->recentChanges)->contains(fn ($item) => str_contains($item->title, 'Old commit')));
            $this->assertTrue($result->freshness['integrations']['github']['stale'] ?? false);

            $narrow = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::RecentChanges,
                windowDays: 1,
                withNarrative: false,
                skipCache: true,
                now: CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
            ));
            $this->assertFalse(collect($narrow->recentChanges)->contains(fn ($item) => str_contains($item->title, 'Old commit')));

            $daily = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::DailyDigest,
                withNarrative: false,
                skipCache: true,
                now: CarbonImmutable::parse('2026-09-06 22:30:00', 'UTC'),
            ));
            $this->assertSame('Europe/Rome', $daily->timezone);

            $archived = app(ProjectService::class)->create($user, 'Dormant');
            app(TaskService::class)->create($user, 'Lingering', projectId: $archived->id);
            app(ProjectService::class)->archive($user, $archived);
            Project::query()->whereKey($archived->id)->update(['updated_at' => CarbonImmutable::parse('2026-08-01 12:00:00', 'UTC')]);

            $attention = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertFalse(collect($attention->attention)->contains(fn ($item) => $item->title === 'Dormant'));

            $active = app(ProjectService::class)->create($user, 'QuietActive');
            $open = app(TaskService::class)->create($user, 'Still todo', projectId: $active->id);
            Project::query()->whereKey($active->id)->update(['updated_at' => CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC')]);
            Task::query()->whereKey($open->id)->update(['updated_at' => CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC')]);
            KnowledgeEvent::query()
                ->where('user_id', $user->id)
                ->where('occurred_at', '>=', CarbonImmutable::parse('2026-09-06 11:00:00', 'UTC'))
                ->update(['occurred_at' => CarbonImmutable::parse('2026-08-20 12:00:00', 'UTC')]);
            $quiet = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
                skipCache: true,
            ));
            $this->assertTrue(collect($quiet->attention)->contains(
                fn ($item) => $item->title === 'QuietActive' && ($item->extra['reason'] ?? '') === 'stale_project',
            ));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_briefs_proactive_caps_ai_fallback_cache_and_no_mutations(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            $task = app(TaskService::class)->create(
                $user,
                'Brief overdue',
                dueAt: CarbonImmutable::parse('2026-09-05 10:00:00', 'UTC'),
            );
            $watchersBefore = Watcher::query()->where('user_id', $user->id)->count();

            $sources = app(ProductivityBriefCollector::class)->collect(
                $user,
                ProductivityBriefMode::Daily,
                CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
                [$task],
                [],
            );
            $text = (new ProductivityBriefRenderer)->deterministic($sources);
            $this->assertStringContainsString('Brief overdue', $text);
            $this->assertTrue($sources->synthesisAttention !== [] || $sources->attention !== []);

            $weekly = app(ProductivityBriefCollector::class)->collect(
                $user,
                ProductivityBriefMode::Weekly,
                CarbonImmutable::parse('2026-09-06 12:00:00', 'UTC'),
                [$task],
                [],
            );
            $weeklyText = (new ProductivityBriefRenderer)->deterministic($weekly);
            $this->assertStringContainsString('Недельный обзор', $weeklyText);

            UserProductivitySetting::query()->create([
                'user_id' => $user->id,
                'proactive_enabled' => true,
            ]);
            for ($i = 0; $i < 3; $i++) {
                JarvisNotification::query()->create([
                    'user_id' => $user->id,
                    'type' => JarvisNotificationType::ProactiveSuggestion,
                    'title' => 'cap '.$i,
                    'body' => 'cap',
                    'dedupe_key' => 'cap-'.$i,
                    'occurred_at' => CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC'),
                ]);
            }
            $created = app(ProactiveDispatchService::class)->dispatchDue(10);
            $this->assertSame(0, $created);

            $fake = new FakeAiChatGateway;
            $fake->exception = new AiProviderException('analysis down');
            $this->app->instance(AiChatGateway::class, $fake);
            $this->app->forgetInstance(SynthesisNarrativeService::class);
            $this->app->forgetInstance(CrossSourceSynthesisService::class);
            $fallback = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: true,
                skipCache: true,
            ));
            $this->assertFalse($fallback->narrativeUsed);
            $this->assertNotSame('', (string) $fallback->summary);

            $this->assertSame($watchersBefore, Watcher::query()->where('user_id', $user->id)->count());

            app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
            ));
            $newTask = app(TaskService::class)->create($user, 'Appears after cache bump');
            $after = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
            ));
            $this->assertTrue(
                collect($after->openWork)->contains(fn ($item) => $item->title === 'Appears after cache bump')
                || collect($after->attention)->contains(fn ($item) => str_contains($item->title, 'Appears after cache bump')),
            );
            unset($newTask);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_context_budget_drops_synthesis_before_memory_and_http_is_scoped(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $assembled = app(ContextBudgetManager::class)->assemble($configuration, new ContextSlices(
                platformPrompt: str_repeat('platform ', 200),
                assistantIdentity: null,
                generalPrompt: null,
                applicationEvent: null,
                currentSummary: null,
                profile: null,
                memoryLines: ['durable memory fact one', 'durable memory fact two'],
                crossChatLines: [],
                recentMessages: [],
                lastIsCurrentTurn: false,
                knowledgeBlock: str_repeat('knowledge-slice ', 4000),
                synthesisBlock: str_repeat('synthesis-slice ', 4000),
            ));
            $this->assertTrue(($assembled['diagnostics']['trimmed']['synthesis_context'] ?? 0) >= 1);
            $this->assertLessThanOrEqual(1, $assembled['diagnostics']['sources']['synthesis_context']['count']);
            $this->assertStringContainsString('durable memory fact', $assembled['system_prompt']);
            $this->assertLessThanOrEqual(
                (int) config('context_budget.synthesis_context', 220) + 80,
                (int) $assembled['diagnostics']['sources']['synthesis_context']['tokens'],
            );
            $this->assertArrayHasKey('synthesis_context', $assembled['diagnostics']['sources']);
            $this->assertLessThanOrEqual(1, $assembled['diagnostics']['sources']['synthesis_context']['count']);

            $this->actingAs($user)->getJson(route('jarvis.synthesis.index'))->assertOk();
            $foreign = $this->ingest($other, KnowledgeEntityType::Person, 'NoPeek', $this->chat($other), 'no-peek');
            $this->actingAs($user)
                ->getJson(route('jarvis.synthesis.entity', $foreign->id))
                ->assertNotFound();
            $this->actingAs($user)
                ->getJson(route('jarvis.synthesis.index', ['project_id' => 999999999]))
                ->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    /**
     * Scenario 8: once the work is closed, nothing derived from it may still look live.
     */
    public function test_completed_work_disappears_from_every_derived_slice_and_reads_as_human_text(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $now = CarbonImmutable::now('UTC');

            $parent = app(TaskService::class)->create($user, 'Проверить новый билд YFS', dueAt: $now->addDay());
            $child = app(TaskService::class)->create(
                $user,
                'Проверить авторизацию',
                dueAt: $now->addDay(),
                parentTaskId: $parent->id,
            );
            $reminder = app(ReminderService::class)->create(
                $user,
                'Проверить задачу «Проверить авторизацию»',
                $now->addDay(),
                'UTC',
                taskId: $child->id,
            );
            $watcher = app(WatcherService::class)->create($user, [
                'name' => 'Проверка авторизации',
                'trigger_type' => 'task_state',
                'condition_type' => 'overdue_by',
                'mode' => 'one_shot',
                'task_id' => $child->id,
            ]);

            // Knowledge evidence of the dependency, named the way extraction names it.
            $parentEntity = $this->ingest($user, KnowledgeEntityType::Topic, 'Задача #'.$parent->id.': Проверить новый билд YFS', $conversation, 'dep-parent');
            $childEntity = $this->ingest($user, KnowledgeEntityType::Topic, 'Задача #'.$child->id.': Проверить авторизацию', $conversation, 'dep-child');
            app(KnowledgeIngestionService::class)->upsertRelationship(
                $user,
                $childEntity,
                $parentEntity,
                KnowledgeRelationType::DependsOn,
                $this->source($conversation, 'dep-rel'),
            );

            app(TaskService::class)->completeOwned($user, $child->id);
            app(TaskService::class)->completeOwned($user, $parent->id);
            app(SynthesisCache::class)->bump($user);

            $this->assertSame(ReminderStatus::Cancelled, $reminder->fresh()->status);
            $this->assertNotSame(WatcherStatus::Active, $watcher->fresh()->status);

            $result = app(CrossSourceSynthesisService::class)->synthesize(new SynthesisScope(
                user: $user,
                type: SynthesisType::AttentionNeeded,
                withNarrative: false,
                skipCache: true,
            ));

            $touches = static fn (array $items, callable $matches): bool => collect($items)
                ->contains(fn ($item) => collect($item->sources)->contains($matches));
            $closedTask = static fn ($source): bool => in_array((int) $source->taskId, [(int) $parent->id, (int) $child->id], true);
            $cancelledReminder = static fn ($source): bool => (int) $source->reminderId === (int) $reminder->id;
            $resolvedWatcher = static fn ($source): bool => (int) $source->watcherId === (int) $watcher->id;

            $this->assertFalse($touches($result->upcoming, $closedTask), 'A completed task is not upcoming.');
            $this->assertFalse($touches($result->upcoming, $cancelledReminder), 'A cancelled reminder is not upcoming.');
            $this->assertFalse($touches($result->waitingFor, $resolvedWatcher), 'A resolved watcher is not waiting.');
            $this->assertFalse($touches($result->openLoops, $resolvedWatcher));
            $this->assertFalse($touches($result->openWork, $closedTask));
            $this->assertFalse(
                collect($result->blockers)->contains(fn ($item) => str_contains($item->title, 'зависит от завершения')),
                'A dependency on completed work is resolved, not a blocker.',
            );
            $this->assertFalse(
                collect($result->attention)->contains(fn ($item) => str_contains($item->title, 'Проверить новый билд YFS')),
            );

            // The completion is reported once, no matter how many domains recorded it.
            $completions = collect($result->recentChanges)
                ->filter(fn ($item) => str_contains($item->title, 'Проверить новый билд YFS') && str_contains($item->title, 'выполнена'))
                ->values();
            $this->assertCount(1, $completions, (string) json_encode($completions->pluck('title')));
            $this->assertSame('Задача «Проверить новый билд YFS» выполнена', $completions->first()->title);

            $prose = collect([...$result->upcoming, ...$result->attention, ...$result->waitingFor, ...$result->recentChanges, ...$result->blockers])
                ->flatMap(fn ($item) => [$item->title, (string) $item->why, (string) $item->recommendedNextStep])
                ->implode(' ');

            foreach (['task_state', 'overdue_by', 'depends_on', 'task_completed', 'knowledge_linked', 'works_on', 'healthy', 'one_shot', '#'.$child->id, '#'.$parent->id] as $leak) {
                $this->assertStringNotContainsString($leak, $prose, 'Users read sentences, not internal fields.');
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * A subtask left open under a completed parent is still work, and must stay visible.
     */
    public function test_open_subtask_of_a_closed_parent_stays_visible_in_the_task_panel(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $parent = app(TaskService::class)->create($user, 'Проверить новый билд YFS');
            $child = app(TaskService::class)->create($user, 'Проверить авторизацию', parentTaskId: $parent->id);

            app(TaskService::class)->completeOwned($user, $parent->id, force: true);

            $panel = app(TaskService::class)->panelFor($user);
            $active = collect([...$panel['overdue'], ...$panel['today'], ...$panel['upcoming'], ...$panel['undated']]);
            $row = $active->firstWhere('id', $child->id);

            $this->assertNotNull($row, 'The still-open subtask has no other card to live in.');
            $this->assertSame('Подзадача задачи «Проверить новый билд YFS»', $row['parent_label']);
            $this->assertSame('Без срока', $row['schedule_label']);
            $this->assertNull($row['state_label']);
            $this->assertSame(1, $panel['active_count']);

            $done = collect($panel['completed'])->firstWhere('id', $parent->id);
            $this->assertSame('0 из 1 подзадачи выполнено', $done['subtask_progress_label']);
            $this->assertTrue($done['reopenable']);
            $this->assertSame(['Проверить авторизацию'], $done['open_subtask_titles']);
            $this->assertSame('Проверить авторизацию', $done['subtasks'][0]['title']);
            $this->assertTrue($done['subtasks'][0]['completable']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function collectorPollsIntegrations(): bool
    {
        $params = (new ReflectionClass(SynthesisFactCollector::class))->getConstructor()?->getParameters() ?? [];

        foreach ($params as $param) {
            $type = $param->getType();
            $name = strtolower(($type instanceof \ReflectionNamedType ? $type->getName() : '').' '.$param->getName());
            if (str_contains($name, 'gmail') || str_contains($name, 'github') || str_contains($name, 'calendar')) {
                return true;
            }
        }

        return false;
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
            type: KnowledgeSourceType::Manual,
            fingerprint: KnowledgeSourceRef::hash('synth', (string) $conversation->id, $key),
            confidence: KnowledgeConfidence::manual(),
            conversationId: $conversation->id,
            manual: true,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ingest(
        User $user,
        KnowledgeEntityType $type,
        string $name,
        Conversation $conversation,
        string $key,
        array $attributes = [],
    ): KnowledgeEntity {
        return app(KnowledgeIngestionService::class)->upsertEntity(
            $user,
            $type,
            $name,
            $this->source($conversation, $key),
            $attributes,
        );
    }
}
