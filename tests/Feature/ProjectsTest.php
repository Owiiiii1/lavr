<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\ConversationSummaryStatus;
use App\Enums\MemoryKind;
use App\Enums\MemoryScope;
use App\Enums\MemoryStatus;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ProjectStatus;
use App\Enums\TopicStatus;
use App\Enums\UserRole;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Project;
use App\Models\Topic;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationContextBuilder;
use App\Services\Conversations\ConversationService;
use App\Services\Memory\PersonalMemoryRetriever;
use App\Services\Projects\Exceptions\ProjectException;
use App\Services\Projects\ProjectContextService;
use App\Services\Projects\ProjectService;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\GetProjectContextTool;
use App\Services\Tools\SearchConversationHistoryTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_projects_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('projects'));
        $this->assertTrue(Schema::hasTable('project_conversations'));
        $this->assertTrue(Schema::hasTable('project_topics'));
        $this->assertTrue(Schema::hasTable('project_memories'));
        $this->assertTrue(Schema::hasTable('project_groups'));
        $this->assertTrue(Schema::hasColumns('projects', ['user_id', 'name', 'normalized_name', 'description', 'status', 'metadata', 'category', 'start_date', 'end_date', 'owner_person_id']));
    }

    public function test_owner_can_create_and_normal_user_cannot_access(): void
    {
        $user = null;
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);
        $name = 'jarvis-test-project-'.Str::lower(Str::random(8));

        try {
            $this->actingAs($owner)->get(route('projects.index'))->assertOk();
            $this->actingAs($owner)->post(route('projects.store'), [
                'name' => $name,
                'description' => 'Test container only.',
            ])->assertRedirect();

            $project = Project::query()->where('user_id', $owner->id)->where('name', $name)->first();
            $this->assertNotNull($project);
            $this->assertSame($owner->id, $project->user_id);

            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('projects.index'))->assertForbidden();
            $this->actingAs($user)->post(route('projects.store'), ['name' => 'Nope'])->assertForbidden();
            $this->actingAs($user)->get(route('projects.show', $project))->assertForbidden();
        } finally {
            Project::query()->where('user_id', $owner->id)->where('name', $name)->delete();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_normalized_name_is_unique_per_owner_and_archive_restore_works(): void
    {
        $user = null;

        try {
            $user = $this->temporaryProjectUser();
            $service = app(ProjectService::class);
            $first = $service->create($user, 'JARVIS Test');
            $this->assertSame('jarvis test', $first->normalized_name);

            try {
                $service->create($user, 'jarvis   test');
                $this->fail('Duplicate project name should fail.');
            } catch (ProjectException $exception) {
                $this->assertSame('duplicate_name', $exception->error);
            }

            $service->archive($user, $first);
            $this->assertSame(ProjectStatus::Archived, $first->fresh()->status);
            $service->restore($user, $first);
            $this->assertSame(ProjectStatus::Active, $first->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_attach_and_detach_own_entities_without_deleting_them(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->temporaryProjectUser();
            $other = $this->createTemporaryUser();
            $service = app(ProjectService::class);
            $project = $service->create($user, 'JARVIS');
            $second = $service->create($user, 'OwlSolutions');

            $conversation = app(ConversationService::class)->createPersonal($user, 'AI infra');
            $foreignConversation = app(ConversationService::class)->createPersonal($other, 'Foreign chat');
            $topic = Topic::query()->create([
                'user_id' => $user->id,
                'name' => 'JARVIS',
                'normalized_name' => 'jarvis',
                'status' => TopicStatus::Active,
            ]);
            $foreignTopic = Topic::query()->create([
                'user_id' => $other->id,
                'name' => 'JARVIS',
                'normalized_name' => 'jarvis',
                'status' => TopicStatus::Active,
            ]);
            $memory = $this->createMemory($user, $conversation, 'Owner works on Jarvis');
            $foreignMemory = $this->createMemory($other, $foreignConversation, 'Other user fact');

            $service->attachConversation($user, $project, $conversation);
            $service->attachConversation($user, $second, $conversation);
            $this->assertSame(2, $conversation->projects()->count());

            try {
                $service->attachConversation($user, $project, $foreignConversation);
                $this->fail('Foreign conversation should be rejected.');
            } catch (ProjectException $exception) {
                $this->assertSame('foreign_conversation', $exception->error);
            }

            $service->attachTopic($user, $project, $topic);
            try {
                $service->attachTopic($user, $project, $foreignTopic);
                $this->fail('Foreign topic should be rejected.');
            } catch (ProjectException $exception) {
                $this->assertSame('foreign_topic', $exception->error);
            }

            $service->attachMemory($user, $project, $memory);
            try {
                $service->attachMemory($user, $project, $foreignMemory);
                $this->fail('Foreign memory should be rejected.');
            } catch (ProjectException $exception) {
                $this->assertSame('foreign_memory', $exception->error);
            }

            $conversationId = $conversation->id;
            $topicId = $topic->id;
            $memoryId = $memory->id;

            $service->detachConversation($user, $project, $conversation);
            $service->detachTopic($user, $project, $topic);
            $service->detachMemory($user, $project, $memory);

            $this->assertTrue(Conversation::query()->whereKey($conversationId)->exists());
            $this->assertTrue(Topic::query()->whereKey($topicId)->exists());
            $this->assertTrue(Memory::query()->whereKey($memoryId)->exists());
            $this->assertSame(1, $conversation->projects()->count());
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_project_retrieval_is_bounded_and_only_returns_attached_derived_context(): void
    {
        $user = null;

        try {
            config([
                'projects.max_memories' => 3,
                'projects.max_topics' => 3,
                'projects.max_summaries' => 2,
            ]);

            $user = $this->temporaryProjectUser();
            $service = app(ProjectService::class);
            $project = $service->create($user, 'JARVIS', 'Разработка персонального AI-ассистента Jarvis.');
            $otherProject = $service->create($user, 'TEST');

            $attachedChat = app(ConversationService::class)->createPersonal($user, 'Jarvis chat');
            $unattachedChat = app(ConversationService::class)->createPersonal($user, 'Unrelated chat');
            Message::query()->create([
                'conversation_id' => $unattachedChat->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'RAW SECRET FROM OTHER CHAT',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);

            ConversationSummary::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $attachedChat->id,
                'summary' => 'Решили делать Memory Engine отдельно от Projects.',
                'message_count' => 8,
                'version' => 1,
                'status' => ConversationSummaryStatus::Current,
                'generated_at' => now(),
            ]);
            ConversationSummary::query()->create([
                'user_id' => $user->id,
                'conversation_id' => $unattachedChat->id,
                'summary' => 'Unrelated summary should not appear.',
                'message_count' => 4,
                'version' => 1,
                'status' => ConversationSummaryStatus::Current,
                'generated_at' => now(),
            ]);

            $service->attachConversation($user, $project, $attachedChat);

            for ($i = 0; $i < 6; $i++) {
                $topic = Topic::query()->create([
                    'user_id' => $user->id,
                    'name' => 'Topic '.$i,
                    'normalized_name' => 'topic '.$i,
                    'status' => TopicStatus::Active,
                ]);
                $memory = $this->createMemory($user, $attachedChat, 'Memory '.$i.' about Jarvis', 0.7 + ($i / 100));
                $service->attachTopic($user, $project, $topic);
                $service->attachMemory($user, $project, $memory);
            }

            $unattachedMemory = $this->createMemory($user, $unattachedChat, 'Should stay out of project context', 0.99);

            $payload = app(ProjectContextService::class)->context($user, $project, 'Jarvis');

            $this->assertSame($project->id, $payload['project']['id']);
            $this->assertLessThanOrEqual(3, count($payload['memories']));
            $this->assertLessThanOrEqual(3, count($payload['topics']));
            $this->assertLessThanOrEqual(2, count($payload['conversation_summaries']));
            $this->assertFalse(collect($payload['memories'])->contains(fn ($row) => str_contains($row['content'], 'Should stay out')));
            $this->assertFalse(collect($payload['conversation_summaries'])->contains(fn ($row) => str_contains((string) $row['summary'], 'Unrelated')));
            $this->assertFalse(json_encode($payload) === false);
            $this->assertStringNotContainsString('RAW SECRET FROM OTHER CHAT', json_encode($payload));

            $empty = app(ProjectContextService::class)->context($user, $otherProject, 'anything');
            $this->assertSame([], $empty['topics']);
            $this->assertSame([], $empty['memories']);
            $this->assertSame([], $empty['conversation_summaries']);

            $package = app(PersonalMemoryRetriever::class)->retrieve($user, $attachedChat, 'Jarvis');
            $this->assertNotEmpty($package->memories);

            $configuration = AiRoleSetting::query()->where('role_key', AiRoleKey::UserConversation->value)->firstOrFail();
            $inbound = Message::query()->create([
                'conversation_id' => $attachedChat->id,
                'user_id' => $user->id,
                'role' => MessageRole::User,
                'channel' => MessageChannel::Web,
                'body' => 'Привет',
                'message_type' => MessageType::Text,
                'occurred_at' => now(),
            ]);
            $context = app(ConversationContextBuilder::class)->build($user, $attachedChat, $configuration, $inbound);
            $this->assertStringNotContainsString('Разработка персонального AI-ассистента Jarvis.', $context['system_prompt']);
            $this->assertStringNotContainsString('RAW SECRET FROM OTHER CHAT', $context['system_prompt']);
            $this->assertFalse(collect($context['messages'])->contains(fn ($message) => str_contains($message->content, 'RAW SECRET')));
            $this->assertSame($unattachedMemory->user_id, $user->id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_get_project_context_is_owner_only_and_existing_tools_remain(): void
    {
        $ownerLike = null;
        $user = null;

        try {
            $ownerLike = $this->temporaryProjectUser();
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($ownerLike, 'Основной');
            $userConversation = app(ConversationService::class)->createPersonal($user, 'Основной');
            $project = app(ProjectService::class)->create($ownerLike, 'JARVIS', 'Owner project description.');

            $registry = app(ToolRegistry::class);
            $ownerTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($ownerLike, $conversation)));
            $userTools = array_map(static fn ($tool) => $tool->name, $registry->definitionsFor(new ToolExecutionContext($user, $userConversation)));

            $this->assertContains(CreateReminderTool::NAME, $ownerTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $ownerTools);
            $this->assertContains(GetProjectContextTool::NAME, $ownerTools);
            $this->assertContains(CreateReminderTool::NAME, $userTools);
            $this->assertContains(SearchConversationHistoryTool::NAME, $userTools);
            $this->assertNotContains(GetProjectContextTool::NAME, $userTools);

            $result = app(GetProjectContextTool::class)->execute(
                new ToolCall('c1', GetProjectContextTool::NAME, ['project' => 'JARVIS']),
                new ToolExecutionContext($ownerLike, $conversation),
            );
            $this->assertTrue($result->success);
            $this->assertSame($project->id, $result->payload['project']['id']);

            $denied = $registry->execute(
                new ToolCall('c2', GetProjectContextTool::NAME, ['project' => 'JARVIS']),
                new ToolExecutionContext($user, $userConversation),
            );
            $this->assertFalse($denied->success);
            $this->assertSame('tool_not_available', $denied->payload['error']);
        } finally {
            $this->deleteTemporaryUser($ownerLike);
            $this->deleteTemporaryUser($user);
        }
    }

    private function temporaryProjectUser(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }

    private function createMemory(User $user, Conversation $conversation, string $content, float $confidence = 0.9): Memory
    {
        return Memory::query()->create([
            'user_id' => $user->id,
            'scope' => MemoryScope::Personal,
            'kind' => MemoryKind::Fact,
            'content' => $content,
            'normalized_key' => Str::lower($content),
            'confidence' => $confidence,
            'status' => MemoryStatus::Active,
            'first_seen_at' => now(),
            'last_confirmed_at' => now(),
        ]);
    }
}
