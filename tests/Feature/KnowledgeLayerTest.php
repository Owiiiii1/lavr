<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\KnowledgeAnalysisRunStatus;
use App\Enums\KnowledgeEntityStatus;
use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeEventType;
use App\Enums\KnowledgeRelationStatus;
use App\Enums\KnowledgeRelationType;
use App\Enums\KnowledgeSourceType;
use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Jobs\ExtractKnowledgeFromSourceJob;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\KnowledgeAnalysisRun;
use App\Models\KnowledgeEntity;
use App\Models\KnowledgeEntitySource;
use App\Models\KnowledgeEvent;
use App\Models\KnowledgeRelationship;
use App\Models\Memory;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\Exceptions\AiProviderException;
use App\Services\Ai\Exceptions\AiSafetyException;
use App\Services\Context\ContextBudgetManager;
use App\Services\Context\ContextSlices;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeExtractor;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Projects\ProjectService;
use App\Services\Tools\Knowledge\GetEntityTool;
use App\Services\Tools\Knowledge\SearchKnowledgeTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class KnowledgeLayerTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_knowledge_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('knowledge_entities'));
        $this->assertTrue(Schema::hasTable('knowledge_entity_aliases'));
        $this->assertTrue(Schema::hasTable('knowledge_relationships'));
        $this->assertTrue(Schema::hasTable('knowledge_events'));
        $this->assertTrue(Schema::hasTable('knowledge_event_entities'));
        $this->assertTrue(Schema::hasTable('knowledge_entity_sources'));
        $this->assertTrue(Schema::hasTable('knowledge_analysis_runs'));
    }

    public function test_creates_entity_from_trusted_source_with_provenance(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $entity = app(KnowledgeIngestionService::class)->upsertEntity(
                $user,
                KnowledgeEntityType::Person,
                'Marco',
                new KnowledgeSourceRef(
                    type: KnowledgeSourceType::Manual,
                    fingerprint: KnowledgeSourceRef::hash('manual', (string) $conversation->id, 'src-1'),
                    confidence: KnowledgeConfidence::manual(),
                    conversationId: $conversation->id,
                    manual: true,
                ),
            );

            $this->assertSame('Marco', $entity->name);
            $this->assertSame(KnowledgeEntityType::Person, $entity->type);
            $this->assertSame(1, $entity->sources()->count());
            $this->assertSame(KnowledgeSourceType::Manual, $entity->sources()->first()?->source_type);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_same_source_is_idempotent_for_entity_relation_and_event(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $entitySource = $this->source($conversation, 'same-ent');
            $relSource = $this->source($conversation, 'same-rel');
            $eventSource = $this->source($conversation, 'same-evt');
            $ingestion = app(KnowledgeIngestionService::class);
            $first = $ingestion->upsertEntity($user, KnowledgeEntityType::Person, 'Ivan', $entitySource);
            $second = $ingestion->upsertEntity($user, KnowledgeEntityType::Person, 'Ivan', $entitySource);
            $project = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'YFS', $this->source($conversation, 'same-proj'));
            $ingestion->upsertRelationship($user, $first, $project, KnowledgeRelationType::WorksOn, $relSource);
            $ingestion->upsertRelationship($user, $first, $project, KnowledgeRelationType::WorksOn, $relSource);
            $ingestion->recordEvent($user, KnowledgeEventType::ManualNote, 'Note', $eventSource, [$first]);
            $ingestion->recordEvent($user, KnowledgeEventType::ManualNote, 'Note', $eventSource, [$first]);

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, KnowledgeEntity::query()->where('user_id', $user->id)->where('type', KnowledgeEntityType::Person)->count());
            $this->assertSame(1, KnowledgeRelationship::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, KnowledgeEvent::query()->where('user_id', $user->id)->where('source_fingerprint', $eventSource->fingerprint)->count());
            $this->assertSame(1, KnowledgeEntitySource::query()->where('knowledge_entity_id', $first->id)->where('source_fingerprint', $entitySource->fingerprint)->count());
            $this->assertSame(1, KnowledgeEntitySource::query()->where('knowledge_entity_id', $first->id)->where('source_fingerprint', $relSource->fingerprint)->count());
            $this->assertSame(1, KnowledgeEntitySource::query()->where('knowledge_entity_id', $first->id)->where('source_fingerprint', $eventSource->fingerprint)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_alias_resolves_to_existing_entity(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);
            $created = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'Young Fashion Show', $this->source($conversation, 'yfs-1'), [
                'aliases' => ['YFS'],
            ]);
            $again = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'YFS', $this->source($conversation, 'yfs-2'));

            $this->assertSame($created->id, $again->id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_duplicate_names_do_not_unsafe_merge(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);
            $full = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'Young Fashion Show', $this->source($conversation, 'dup-1'));
            $short = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'YFS', $this->source($conversation, 'dup-2'));

            $this->assertNotSame($full->id, $short->id);
            $this->assertSame(2, KnowledgeEntity::query()->where('user_id', $user->id)->where('type', KnowledgeEntityType::Project)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_relationship_upsert_and_supersede_keep_history(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);
            $person = $ingestion->upsertEntity($user, KnowledgeEntityType::Person, 'Marco', $this->source($conversation, 'rel-p'));
            $project = $ingestion->upsertEntity($user, KnowledgeEntityType::Project, 'YFS', $this->source($conversation, 'rel-y'));
            $active = $ingestion->upsertRelationship($user, $person, $project, KnowledgeRelationType::WorksOn, $this->source($conversation, 'rel-a'));
            $this->assertSame(KnowledgeRelationStatus::Active, $active->status);

            $inactive = $ingestion->upsertRelationship(
                $user,
                $person,
                $project,
                KnowledgeRelationType::WorksOn,
                $this->source($conversation, 'rel-b'),
                deactivate: true,
            );

            $this->assertSame($active->id, $inactive->id);
            $this->assertSame(KnowledgeRelationStatus::Inactive, $inactive->status);
            $this->assertNotNull($inactive->superseded_at);
            $this->assertSame(1, KnowledgeRelationship::query()->where('user_id', $user->id)->count());
            $this->assertTrue(
                KnowledgeEvent::query()->where('user_id', $user->id)->where('type', KnowledgeEventType::RelationshipSuperseded)->exists(),
            );
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_foreign_user_cannot_read_or_tool_foreign_entity(): void
    {
        $ownerUser = null;
        $other = null;

        try {
            $ownerUser = $this->createTemporaryUser();
            $other = $this->createTemporaryUser();
            $conversation = $this->chat($ownerUser);
            $entity = $this->ingestPerson($ownerUser, 'Secret Person', $conversation, 'iso-1');
            $otherChat = $this->chat($other);

            $this->actingAs($other)
                ->getJson(route('jarvis.knowledge.entities.show', $entity->id))
                ->assertNotFound();

            $result = app(GetEntityTool::class)->execute(
                new ToolCall('g1', GetEntityTool::NAME, ['entity_id' => $entity->id]),
                new ToolExecutionContext($other, $otherChat),
            );

            $this->assertFalse($result->success);
            $this->assertSame('not_found', $result->payload['error']);
        } finally {
            $this->deleteTemporaryUser($ownerUser);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_ordinary_user_cannot_access_owner_graph(): void
    {
        $owner = null;
        $user = null;

        try {
            $owner = $this->createTemporaryUser();
            $owner->forceFill(['role' => UserRole::Owner])->save();
            $user = $this->createTemporaryUser();
            $entity = $this->ingestPerson($owner, 'Owner Only', $this->chat($owner), 'own-1');

            $this->actingAs($user)
                ->getJson(route('jarvis.knowledge.entities.show', $entity->id))
                ->assertNotFound();
        } finally {
            $this->deleteTemporaryUser($owner);
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_project_remains_authoritative_and_is_not_duplicated_as_truth(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $user->forceFill(['role' => UserRole::Owner])->save();
            $projects = app(ProjectService::class);
            $project = $projects->create($user, 'YFS', 'Canonical description');
            $index = KnowledgeEntity::query()
                ->where('user_id', $user->id)
                ->where('project_id', $project->id)
                ->where('type', KnowledgeEntityType::Project)
                ->first();

            $this->assertNotNull($index);
            $this->assertSame('YFS', $project->name);
            $this->assertSame('Canonical description', $project->description);
            $this->assertSame(ProjectStatus::Active, $project->status);
            $this->assertSame($project->id, $index->project_id);
            $this->assertSame(KnowledgeEntityStatus::Active, $index->status);

            $project = $projects->archive($user, $project);
            $index->refresh();

            $this->assertSame(ProjectStatus::Archived, $project->status);
            $this->assertSame(KnowledgeEntityStatus::Active, $index->status);
            $this->assertSame($project->id, $index->project_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_conversation_delete_detaches_provenance_and_keeps_entity_with_other_sources(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);
            $entity = $ingestion->upsertEntity($user, KnowledgeEntityType::Person, 'Kept', $this->source($conversation, 'del-auto'));
            $ingestion->attachSource($entity, new KnowledgeSourceRef(
                type: KnowledgeSourceType::Manual,
                fingerprint: KnowledgeSourceRef::hash('manual-keep', (string) $entity->id),
                confidence: KnowledgeConfidence::manual(),
                manual: true,
            ));

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $entity->refresh();
            $this->assertTrue($entity->exists);
            $this->assertSame(KnowledgeEntityStatus::Active, $entity->status);
            $this->assertNull($entity->sources()->where('source_type', KnowledgeSourceType::Conversation)->first()?->conversation_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_auto_derived_entity_becomes_orphan_when_last_source_detaches(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $entity = app(KnowledgeIngestionService::class)->upsertEntity(
                $user,
                KnowledgeEntityType::Person,
                'Orphan',
                $this->source($conversation, 'orphan-1'),
            );

            app(ConversationService::class)->deletePersonal($user, $conversation);

            $entity->refresh();
            $this->assertSame(KnowledgeEntityStatus::OrphanCandidate, $entity->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_stale_source_extraction_is_terminal(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $job = new ExtractKnowledgeFromSourceJob(
                $user->id,
                KnowledgeSourceType::Memory->value,
                KnowledgeSourceRef::hash('missing-memory', (string) $user->id),
                9_999_999,
            );
            $job->handle(app(KnowledgeExtractor::class));

            $run = KnowledgeAnalysisRun::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($run);
            $this->assertSame(KnowledgeAnalysisRunStatus::Failed, $run->status);
            $this->assertSame('stale_source', $run->last_error);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_provider_transient_error_retries_and_safety_is_terminal(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $user = $this->createTemporaryUser();
            $memory = $this->memory($user);

            $fake->exception = new AiProviderException('Gemini chat request failed with status 429');
            $job = new ExtractKnowledgeFromSourceJob(
                $user->id,
                KnowledgeSourceType::Memory->value,
                KnowledgeSourceRef::hash('retry-mem', (string) $memory->id),
                (int) $memory->id,
            );

            try {
                $job->handle(app(KnowledgeExtractor::class));
                $this->fail('Retryable provider error should be rethrown.');
            } catch (AiProviderException) {
            }

            $run = KnowledgeAnalysisRun::query()->where('user_id', $user->id)->latest('id')->first();
            $this->assertSame(KnowledgeAnalysisRunStatus::Processing, $run?->status);

            $fake->exception = new AiSafetyException;
            $safeJob = new ExtractKnowledgeFromSourceJob(
                $user->id,
                KnowledgeSourceType::Memory->value,
                KnowledgeSourceRef::hash('safety-mem', (string) $memory->id),
                (int) $memory->id,
            );
            $safeJob->handle(app(KnowledgeExtractor::class));

            $safeRun = KnowledgeAnalysisRun::query()
                ->where('user_id', $user->id)
                ->where('source_fingerprint', $safeJob->sourceFingerprint)
                ->first();
            $this->assertSame(KnowledgeAnalysisRunStatus::Failed, $safeRun?->status);
            $this->assertSame('provider_safety', $safeRun?->last_error);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_context_budget_bounds_knowledge_and_does_not_inject_full_graph(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $ingestion = app(KnowledgeIngestionService::class);

            for ($index = 1; $index <= 8; $index++) {
                $ingestion->upsertEntity($user, KnowledgeEntityType::Person, 'Person '.$index, $this->source($conversation, 'ctx-'.$index));
            }

            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $context = app(ConversationContextBuilder::class)->build($user, $conversation, $configuration);

            $this->assertArrayHasKey('knowledge_context', $context['diagnostics']['sources']);
            $this->assertLessThanOrEqual(1, $context['diagnostics']['sources']['knowledge_context']['count']);
            $this->assertStringNotContainsString('Person 8', $context['system_prompt']);

            $huge = str_repeat('knowledge-slice ', 4000);
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
                knowledgeBlock: $huge,
            ));

            $this->assertTrue(($assembled['diagnostics']['trimmed']['knowledge_context'] ?? 0) >= 1);
            $this->assertStringContainsString('durable memory fact', $assembled['system_prompt']);
            $this->assertSame(0, substr_count($assembled['system_prompt'], 'Person 1'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_search_knowledge_returns_compact_results(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = $this->chat($user);
            $this->ingestPerson($user, 'Marco Rossi', $conversation, 'search-1');

            $result = app(SearchKnowledgeTool::class)->execute(
                new ToolCall('s1', SearchKnowledgeTool::NAME, ['query' => 'Marco']),
                new ToolExecutionContext($user, $conversation),
            );

            $this->assertTrue($result->success);
            $this->assertSame(1, $result->payload['count']);
            $this->assertSame(['id', 'type', 'name', 'summary', 'status', 'project_id', 'confidence'], array_keys($result->payload['entities'][0]));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_no_watchers_or_external_polling_were_introduced(): void
    {
        $this->assertFalse(class_exists('App\\Jobs\\KnowledgeWatcherJob'));
        $this->assertFalse(class_exists('App\\Services\\Knowledge\\KnowledgeWatcher'));
        $console = File::get(base_path('routes/console.php'));
        $this->assertStringNotContainsString('knowledge:watch', $console);
        $this->assertStringNotContainsString('KnowledgeWatcher', $console);
        $this->assertStringNotContainsString('gmail:watch', $console);

        $exit = Artisan::call('jarvis:knowledge:backfill');
        $this->assertSame(1, $exit);
    }

    public function test_knowledge_tools_are_registered_for_regular_users(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $names = array_map(
                static fn ($tool) => $tool->name,
                app(ToolRegistry::class)->definitionsFor(new ToolExecutionContext($user, $this->chat($user))),
            );

            $this->assertContains(SearchKnowledgeTool::NAME, $names);
            $this->assertContains(GetEntityTool::NAME, $names);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_http_index_is_user_scoped(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->ingestPerson($user, 'Visible', $this->chat($user), 'http-1');

            $this->actingAs($user)
                ->getJson(route('jarvis.knowledge.index'))
                ->assertOk()
                ->assertJsonPath('counts.people', 1);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function chat(User $user): Conversation
    {
        return app(ConversationService::class)->createPersonal($user, 'Основной');
    }

    private function ingestPerson(User $user, string $name, Conversation $conversation, string $key): KnowledgeEntity
    {
        return app(KnowledgeIngestionService::class)->upsertEntity(
            $user,
            KnowledgeEntityType::Person,
            $name,
            $this->source($conversation, $key),
        );
    }

    private function source(Conversation $conversation, string $key): KnowledgeSourceRef
    {
        return new KnowledgeSourceRef(
            type: KnowledgeSourceType::Conversation,
            fingerprint: KnowledgeSourceRef::hash('test', (string) $conversation->id, $key),
            confidence: KnowledgeConfidence::deterministic(),
            conversationId: $conversation->id,
        );
    }

    private function memory(User $user): Memory
    {
        return Memory::query()->create([
            'user_id' => $user->id,
            'scope' => MemoryScope::Personal,
            'kind' => MemoryKind::Fact,
            'content' => 'Client for YFS is John.',
            'normalized_key' => 'yfs-client-john',
            'confidence' => 0.9,
            'status' => MemoryStatus::Active,
            'first_seen_at' => now(),
            'last_confirmed_at' => now(),
        ]);
    }

    private function bindFake(): FakeAiChatGateway
    {
        Http::preventStrayRequests();
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);

        return $fake;
    }
}
