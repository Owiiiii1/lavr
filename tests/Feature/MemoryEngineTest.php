<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\ConversationSummaryStatus;
use App\Enums\MemoryAction;
use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemorySourceKind;
use App\Enums\MemoryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Jobs\AnalyzeConversationTurnJob;
use App\Jobs\UpdateConversationSummaryJob;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\MemoryAnalysisRun;
use App\Models\MemoryRevision;
use App\Models\MemorySource;
use App\Models\Message;
use App\Models\MessageTopicRelation;
use App\Models\Topic;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ChannelContext;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Conversations\ConversationTurnService;
use App\Services\Memory\ConversationSummaryService;
use App\Services\Memory\ConversationTurnAnalyzer;
use App\Services\Memory\DTO\MemoryAnalysisResult;
use App\Services\Memory\DTO\MemoryCandidate;
use App\Services\Memory\DTO\TopicCandidate;
use App\Services\Memory\Exceptions\MemoryAnalysisException;
use App\Services\Memory\MemoryAnalysisResultParser;
use App\Services\Memory\MemoryWriter;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Memory\UserProfileService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class MemoryEngineTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_memory_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('conversation_summaries'));
        $this->assertTrue(Schema::hasTable('topics'));
        $this->assertTrue(Schema::hasTable('message_topic_relations'));
        $this->assertTrue(Schema::hasTable('memories'));
        $this->assertTrue(Schema::hasTable('memory_sources'));
        $this->assertTrue(Schema::hasTable('memory_revisions'));
        $this->assertTrue(Schema::hasTable('user_profiles'));
        $this->assertTrue(Schema::hasTable('memory_analysis_runs'));
        $this->assertTrue(Schema::hasColumns('conversation_summaries', [
            'user_id', 'conversation_id', 'summary', 'from_message_id', 'to_message_id', 'message_count', 'version', 'status',
        ]));
        $this->assertTrue(Schema::hasColumns('memories', [
            'user_id', 'scope', 'kind', 'content', 'normalized_key', 'confidence', 'status', 'valid_from', 'valid_until',
        ]));
    }

    public function test_summary_belongs_to_user_and_conversation_and_is_incremental(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $first = $this->addDialogue($conversation, 'Обсудили Unreal', 'Хорошо, продолжим Unreal.');
            $rawBodies = Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('body')->all();

            $fake->analysisResponseText = '{"summary":"Говорили про Unreal."}';
            $firstResult = app(ConversationSummaryService::class)->update($user, $conversation);

            $this->assertNotNull($firstResult);
            $this->assertSame($user->id, $firstResult['summary']->user_id);
            $this->assertSame($conversation->id, $firstResult['summary']->conversation_id);
            $this->assertSame(1, $firstResult['summary']->version);
            $this->assertSame(ConversationSummaryStatus::Current, $firstResult['summary']->status);

            $this->addDialogue($conversation, 'Решили использовать Niagara', 'Ок, Niagara.');
            $fake->analysisResponseText = '{"summary":"Unreal и решили Niagara."}';
            $second = app(ConversationSummaryService::class)->update($user, $conversation);

            $this->assertSame(2, $second['summary']->version);
            $this->assertSame(ConversationSummaryStatus::Current, $second['summary']->fresh()->status);
            $this->assertSame(ConversationSummaryStatus::Superseded, $firstResult['summary']->fresh()->status);
            $this->assertSame($rawBodies, Message::query()->where('conversation_id', $conversation->id)->whereIn('id', collect($first)->pluck('id'))->orderBy('id')->pluck('body')->all());
            $this->assertStringContainsString('Niagara', $second['summary']->summary);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_summary_job_is_idempotent_for_same_range(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $this->addDialogue($conversation, 'Тема A', 'Ответ A');
            $fake->analysisResponseText = '{"summary":"Тема A."}';

            $job = new UpdateConversationSummaryJob($user->id, $conversation->id, true);
            $job->handle(app(ConversationSummaryService::class));
            $job->handle(app(ConversationSummaryService::class));

            $this->assertSame(1, ConversationSummary::query()->where('conversation_id', $conversation->id)->where('status', ConversationSummaryStatus::Current)->count());
            $this->assertSame(1, ConversationSummary::query()->where('conversation_id', $conversation->id)->count());
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_memory_requires_provenance_and_reinforce_does_not_duplicate(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $message = $this->addUserMessage($conversation, 'Запомни: любимый тестовый цвет бирюзовый');

            $writer = app(MemoryWriter::class);
            $candidate = new MemoryCandidate(
                kind: MemoryKind::Preference->value,
                content: 'Любимый тестовый цвет — бирюзовый',
                normalizedKey: 'favorite test color',
                confidence: 0.9,
                action: MemoryAction::Create->value,
                sourceMessageIds: [$message->id],
            );

            $writer->apply($user, $conversation->id, new MemoryAnalysisResult(memories: [$candidate]), [$message->id]);
            $writer->apply($user, $conversation->id, new MemoryAnalysisResult(memories: [
                new MemoryCandidate(
                    kind: MemoryKind::Preference->value,
                    content: 'Любимый тестовый цвет — бирюзовый',
                    normalizedKey: 'favorite test color',
                    confidence: 0.95,
                    action: MemoryAction::Reinforce->value,
                    sourceMessageIds: [$message->id],
                ),
            ]), [$message->id]);

            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());
            $memory = Memory::query()->where('user_id', $user->id)->first();
            $this->assertSame(0.95, (float) $memory->confidence);
            $this->assertGreaterThan(0, MemorySource::query()->where('memory_id', $memory->id)->count());

            $ignored = $writer->apply($user, $conversation->id, new MemoryAnalysisResult(memories: [
                new MemoryCandidate(
                    kind: MemoryKind::Fact->value,
                    content: 'без источника',
                    normalizedKey: 'no source',
                    confidence: 0.9,
                    action: MemoryAction::Create->value,
                    sourceMessageIds: [],
                ),
            ]), []);
            $this->assertSame(1, $ignored->ignored);
            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_supersede_keeps_revision_and_old_row(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $first = $this->addUserMessage($conversation, 'Машина в сервисе');
            $second = $this->addUserMessage($conversation, 'Машину уже забрал');
            $writer = app(MemoryWriter::class);

            $writer->apply($user, $conversation->id, new MemoryAnalysisResult(memories: [
                new MemoryCandidate(
                    kind: MemoryKind::Fact->value,
                    content: 'Машина в сервисе',
                    normalizedKey: 'car status',
                    confidence: 0.9,
                    action: MemoryAction::Create->value,
                    sourceMessageIds: [$first->id],
                ),
            ]), [$first->id]);

            $writer->apply($user, $conversation->id, new MemoryAnalysisResult(memories: [
                new MemoryCandidate(
                    kind: MemoryKind::Fact->value,
                    content: 'Машину уже забрал',
                    normalizedKey: 'car status',
                    confidence: 0.92,
                    action: MemoryAction::Supersede->value,
                    supersedeNormalizedKey: 'car status',
                    sourceMessageIds: [$second->id],
                ),
            ]), [$second->id]);

            $this->assertSame(2, Memory::query()->where('user_id', $user->id)->count());
            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->where('status', MemoryStatus::Superseded)->count());
            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->where('status', MemoryStatus::Active)->count());
            $this->assertSame('Машину уже забрал', Memory::query()->where('user_id', $user->id)->where('status', MemoryStatus::Active)->value('content'));
            $this->assertGreaterThan(0, MemoryRevision::query()->whereIn('memory_id', Memory::query()->where('user_id', $user->id)->pluck('id'))->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_retrieval_excludes_expired_and_low_confidence_and_isolates_users(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $chatA = app(ConversationService::class)->createPersonal($userA, 'A1');
            $chatB = app(ConversationService::class)->createPersonal($userB, 'B1');
            $otherA = app(ConversationService::class)->createPersonal($userA, 'A2');

            $this->seedMemory($userA, $chatA, 'Любимый тестовый цвет — бирюзовый', 'favorite test color', 0.9);
            $this->seedMemory($userA, $chatA, 'Секретный факт A', 'secret a', 0.4);
            $this->seedMemory($userA, $chatA, 'В Риме до вчера', 'rome trip', 0.9, now()->subDay());
            $this->seedMemory($userB, $chatB, 'Любимый тестовый цвет — красный', 'favorite test color', 0.99);

            ConversationSummary::query()->create([
                'user_id' => $userA->id,
                'conversation_id' => $otherA->id,
                'summary' => 'В чате A2 решили использовать Niagara.',
                'message_count' => 8,
                'version' => 1,
                'status' => ConversationSummaryStatus::Current,
                'generated_at' => now(),
            ]);
            ConversationSummary::query()->create([
                'user_id' => $userB->id,
                'conversation_id' => $chatB->id,
                'summary' => 'User B обсуждал Niagara.',
                'message_count' => 8,
                'version' => 1,
                'status' => ConversationSummaryStatus::Current,
                'generated_at' => now(),
            ]);

            $this->addUserMessage($otherA, 'raw from chat A2 should not leak');

            $package = app(PersonalMemoryRetriever::class)->retrieve($userA, $chatA, 'какой мой любимый тестовый цвет Niagara');
            $contents = collect($package->memories)->pluck('content')->all();

            $this->assertTrue(collect($contents)->contains(fn ($content) => str_contains((string) $content, 'бирюзовый')));
            $this->assertFalse(collect($contents)->contains(fn ($content) => str_contains((string) $content, 'красный')));
            $this->assertFalse(collect($contents)->contains(fn ($content) => str_contains((string) $content, 'Секретный факт A')));
            $this->assertFalse(collect($contents)->contains(fn ($content) => str_contains((string) $content, 'Риме')));
            $this->assertCount(1, $package->crossChatSummaries);
            $this->assertStringContainsString('Niagara', $package->crossChatSummaries[0]->summary);

            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $inbound = $this->addUserMessage($chatA, 'Какой мой любимый тестовый цвет и что решили про Niagara?');
            $context = app(ConversationContextBuilder::class)->build($userA, $chatA, $configuration, $inbound);

            $this->assertStringContainsString('Relevant personal memory:', $context['system_prompt']);
            $this->assertStringContainsString('бирюзовый', $context['system_prompt']);
            $this->assertStringContainsString('Relevant summaries from other chats', $context['system_prompt']);
            $this->assertStringContainsString('Niagara', $context['system_prompt']);
            $bodies = array_map(static fn ($message): string => $message->content, $context['messages']);
            $this->assertContains('Какой мой любимый тестовый цвет и что решили про Niagara?', $bodies);
            $this->assertNotContains('raw from chat A2 should not leak', $bodies);
            $this->assertFalse(collect($bodies)->contains(fn (string $body): bool => str_contains($body, 'красный')));
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_topics_are_personal_and_messages_can_have_many(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $chatA = app(ConversationService::class)->createPersonal($userA, 'A');
            $chatB = app(ConversationService::class)->createPersonal($userB, 'B');
            $messageA = $this->addUserMessage($chatA, 'Работа и Unreal');
            $messageB = $this->addUserMessage($chatB, 'Работа и Unreal');

            app(MemoryWriter::class)->apply($userA, $chatA->id, new MemoryAnalysisResult(topics: [
                new TopicCandidate('Работа', messageIds: [$messageA->id]),
                new TopicCandidate('Unreal Engine', messageIds: [$messageA->id]),
            ]), [$messageA->id]);
            app(MemoryWriter::class)->apply($userB, $chatB->id, new MemoryAnalysisResult(topics: [
                new TopicCandidate('Работа', messageIds: [$messageB->id]),
            ]), [$messageB->id]);

            $this->assertSame(2, Topic::query()->where('user_id', $userA->id)->count());
            $this->assertSame(1, Topic::query()->where('user_id', $userB->id)->count());
            $this->assertSame(2, MessageTopicRelation::query()->where('message_id', $messageA->id)->count());
            $this->assertNull(Topic::query()->where('user_id', $userB->id)->where('normalized_name', 'unreal engine')->first());
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_retrieval_bounds_memory_and_summary_counts(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $current = app(ConversationService::class)->createPersonal($user, 'Current');

            for ($i = 0; $i < 15; $i++) {
                $conversation = app(ConversationService::class)->createPersonal($user, 'Chat '.$i);
                $this->seedMemory($user, $conversation, 'Факт номер '.$i.' про Jarvis', 'jarvis fact '.$i, 0.9);
                ConversationSummary::query()->create([
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'summary' => 'Summary '.$i.' про Jarvis',
                    'message_count' => 20,
                    'version' => 1,
                    'status' => ConversationSummaryStatus::Current,
                    'generated_at' => now()->subMinutes(15 - $i),
                ]);
            }

            $package = app(PersonalMemoryRetriever::class)->retrieve($user, $current, 'Jarvis');
            $this->assertLessThanOrEqual((int) config('memory.retrieval.max_memories'), count($package->memories));
            $this->assertLessThanOrEqual((int) config('memory.retrieval.max_cross_chat_summaries'), count($package->crossChatSummaries));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_search_history_tool_is_scoped_and_bounded_and_reminder_still_registered(): void
    {
        $userA = null;
        $userB = null;

        try {
            $userA = $this->createTemporaryUser();
            $userB = $this->createTemporaryUser();
            $chatA = app(ConversationService::class)->createPersonal($userA, 'Unreal');
            $chatB = app(ConversationService::class)->createPersonal($userB, 'Unreal');
            $this->addUserMessage($chatA, 'Решили взять Niagara для эффектов');
            $this->addUserMessage($chatB, 'Решили взять Cascade для эффектов');

            $registry = app(ToolRegistry::class);
            $context = new ToolExecutionContext($userA, $chatA);
            $names = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor($context));
            $this->assertContains(CreateReminderTool::NAME, $names);
            $this->assertContains(SearchConversationHistoryTool::NAME, $names);

            $tool = app(SearchConversationHistoryTool::class);
            $result = $tool->execute(new ToolCall('c1', SearchConversationHistoryTool::NAME, [
                'query' => 'Niagara Unreal',
                'limit' => 50,
            ]), $context);

            $this->assertTrue($result->success);
            $this->assertLessThanOrEqual((int) config('memory.search.max_snippets'), $result->payload['count']);
            $snippets = collect($result->payload['snippets']);
            $this->assertTrue($snippets->contains(fn ($row) => str_contains($row['snippet'], 'Niagara')));
            $this->assertFalse($snippets->contains(fn ($row) => str_contains($row['snippet'], 'Cascade')));
            $this->assertTrue($snippets->every(fn ($row) => Conversation::query()->whereKey($row['conversation_id'])->where('user_id', $userA->id)->exists()));
        } finally {
            $this->deleteTemporaryUser($userA);
            $this->deleteTemporaryUser($userB);
        }
    }

    public function test_post_turn_dispatches_analysis_job_and_duplicate_run_is_idempotent(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            Bus::fake([AnalyzeConversationTurnJob::class, UpdateConversationSummaryJob::class]);
            $this->bindFake();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');

            app(ConversationTurnService::class)->handleUserMessage(
                $user,
                $conversation,
                'Запомни, что я программист',
                new ChannelContext(MessageChannel::Web, 'mem-1'),
            );

            Bus::assertDispatched(AnalyzeConversationTurnJob::class);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_analysis_job_succeeds_with_fake_and_malformed_output_writes_nothing(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = $this->bindFake();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            [$from, $to] = $this->addDialogue($conversation, 'Запомни: любимый тестовый цвет бирюзовый', 'Запомнил.');

            $fake->analysisResponseText = '{"topics":[{"name":"Цвета","message_ids":['.$from->id.']}],"memories":[{"kind":"preference","content":"Любимый тестовый цвет — бирюзовый","normalized_key":"favorite test color","confidence":0.91,"action":"create","source_message_ids":['.$from->id.']}]}';
            $job = new AnalyzeConversationTurnJob($user->id, $conversation->id, $from->id, $to->id);
            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());
            $this->assertSame('completed', MemoryAnalysisRun::query()->where('user_id', $user->id)->first()?->status->value);

            $job->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));
            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());

            $second = $this->addDialogue($conversation, 'Ещё факт', 'Ок');
            $fake->analysisResponseText = 'this is not json';
            $rawCount = Message::query()->where('conversation_id', $conversation->id)->count();
            $failedJob = new AnalyzeConversationTurnJob($user->id, $conversation->id, $second[0]->id, $second[1]->id);
            $failedJob->handle(app(ConversationTurnAnalyzer::class), app(UserProfileService::class));

            $this->assertSame(1, Memory::query()->where('user_id', $user->id)->count());
            $this->assertSame($rawCount, Message::query()->where('conversation_id', $conversation->id)->count());
            $this->assertSame(
                Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('body')->all(),
                Message::query()->where('conversation_id', $conversation->id)->orderBy('id')->pluck('body')->all(),
            );
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_parser_rejects_malformed_structured_output(): void
    {
        $parser = app(MemoryAnalysisResultParser::class);
        $this->expectException(MemoryAnalysisException::class);
        $parser->parse('{"memories":[{"kind":"nope","content":"x","confidence":2,"action":"create"}]}', [1]);
    }

    public function test_third_party_user_memory_admin_is_not_routed(): void
    {
        $this->assertFalse(Route::has('settings.users.memory.show'));
        $this->assertFalse(Route::has('settings.users.store'));
        $this->assertFalse(Route::has('settings.users.impersonate'));
    }

    public function test_search_and_reminder_share_multi_tool_loop(): void
    {
        $user = null;

        try {
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::UserConversation);
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $this->createTemporaryTelegramIdentity($user = $this->createTemporaryUser(), '950001');
            $fake = $this->bindFake();
            $conversation = app(ConversationService::class)->getOrCreateDefault($user);

            $fake->queueToolThenText(SearchConversationHistoryTool::NAME, [
                'query' => 'Unreal',
            ], 'Нашёл прошлый чат про Unreal.');

            app(ConversationTurnService::class)->handleUserMessage(
                $user,
                $conversation,
                'Что мы решали про Unreal?',
                new ChannelContext(MessageChannel::Web, 'search-1'),
            );

            $conversationCalls = $fake->conversationCalls();
            $this->assertGreaterThanOrEqual(2, count($conversationCalls));
            $this->assertNotEmpty($conversationCalls[0]['request']->tools);
            $toolNames = array_map(static fn ($tool) => $tool->name, $conversationCalls[0]['request']->tools);
            $this->assertContains(CreateReminderTool::NAME, $toolNames);
            $this->assertContains(SearchConversationHistoryTool::NAME, $toolNames);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTelegramIdentity('950001');
            $this->deleteTemporaryUser($user);
        }
    }

    private function bindFake(): FakeAiChatGateway
    {
        $fake = new FakeAiChatGateway;
        $this->app->instance(AiChatGateway::class, $fake);

        return $fake;
    }

    /**
     * @return array{0: Message, 1: Message}
     */
    private function addDialogue($conversation, string $userBody, string $assistantBody): array
    {
        $userMessage = $this->addUserMessage($conversation, $userBody);
        $assistant = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role' => MessageRole::Assistant,
            'channel' => MessageChannel::Web,
            'body' => $assistantBody,
            'message_type' => MessageType::Text,
            'parent_message_id' => $userMessage->id,
            'occurred_at' => now(),
        ]);

        return [$userMessage, $assistant];
    }

    private function addUserMessage($conversation, string $body): Message
    {
        return Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $conversation->user_id,
            'role' => MessageRole::User,
            'channel' => MessageChannel::Web,
            'body' => $body,
            'message_type' => MessageType::Text,
            'occurred_at' => now(),
        ]);
    }

    private function seedMemory($user, $conversation, string $content, string $key, float $confidence, $validUntil = null): Memory
    {
        $message = $this->addUserMessage($conversation, $content);
        $memory = Memory::query()->create([
            'user_id' => $user->id,
            'scope' => MemoryScope::Personal,
            'kind' => MemoryKind::Fact,
            'content' => $content,
            'normalized_key' => $key,
            'confidence' => $confidence,
            'status' => MemoryStatus::Active,
            'valid_until' => $validUntil,
            'first_seen_at' => now(),
            'last_confirmed_at' => now(),
        ]);
        MemorySource::query()->create([
            'memory_id' => $memory->id,
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'source_kind' => MemorySourceKind::DirectConversation,
        ]);

        return $memory;
    }
}
