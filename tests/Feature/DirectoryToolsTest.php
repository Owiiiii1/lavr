<?php

namespace Tests\Feature;

use App\Enums\KnowledgeEntityType;
use App\Enums\KnowledgeSourceType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationService;
use App\Services\Directory\DirectoryService;
use App\Services\Knowledge\DTO\KnowledgeSourceRef;
use App\Services\Knowledge\KnowledgeConfidence;
use App\Services\Knowledge\KnowledgeIngestionService;
use App\Services\Projects\ProjectService;
use App\Services\Tools\Directory\FindPersonTool;
use App\Services\Tools\Directory\FindProjectTool;
use App\Services\Tools\Directory\GetPersonTool;
use App\Services\Tools\Directory\GetProjectTool;
use App\Services\Tools\Synthesis\GetPersonStatusTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class DirectoryToolsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_find_and_get_person_and_project_read_structured_rows(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Directory tools');
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, [
                'display_name' => 'Sonia '.Str::random(6),
                'roles' => ['employee'],
            ]);
            $directory->upsertEmployeeProfile($user, $person, ['position' => 'Producer']);
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(6));
            $directory->attachPersonToProject($user, $project, $person, 'producer');
            $context = new ToolExecutionContext($user, $conversation);

            $found = app(FindPersonTool::class)->execute(
                new ToolCall('t1', FindPersonTool::NAME, ['query' => $person->display_name]),
                $context,
            );
            $this->assertTrue($found->success);
            $this->assertSame($person->id, $found->payload['people'][0]['id']);

            $loaded = app(GetPersonTool::class)->execute(
                new ToolCall('t2', GetPersonTool::NAME, ['person_id' => $person->id]),
                $context,
            );
            $this->assertTrue($loaded->success);
            $this->assertSame('Producer', $loaded->payload['person']['position']);
            $this->assertSame($project->id, $loaded->payload['person']['projects'][0]['id']);

            $foundProject = app(FindProjectTool::class)->execute(
                new ToolCall('t3', FindProjectTool::NAME, ['query' => $project->name]),
                $context,
            );
            $this->assertTrue($foundProject->success);
            $this->assertSame($project->id, $foundProject->payload['projects'][0]['id']);

            $loadedProject = app(GetProjectTool::class)->execute(
                new ToolCall('t4', GetProjectTool::NAME, ['project_id' => $project->id]),
                $context,
            );
            $this->assertTrue($loadedProject->success);
            $this->assertSame($person->id, $loadedProject->payload['project']['people'][0]['id']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_get_person_status_prefers_directory_then_falls_back_to_knowledge(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Status');
            $directory = app(DirectoryService::class);
            $person = $directory->createPerson($user, [
                'display_name' => 'Sergiy '.Str::random(6),
                'roles' => ['employee'],
            ]);
            $context = new ToolExecutionContext($user, $conversation);

            $structured = app(GetPersonStatusTool::class)->execute(
                new ToolCall('s1', GetPersonStatusTool::NAME, ['person' => $person->display_name]),
                $context,
            );
            $this->assertTrue($structured->success);
            $this->assertSame('directory', $structured->payload['source']);
            $this->assertSame($person->id, $structured->payload['id']);

            $knowledgeName = 'Legacy '.Str::random(8);
            app(KnowledgeIngestionService::class)->upsertEntity(
                $user,
                KnowledgeEntityType::Person,
                $knowledgeName,
                new KnowledgeSourceRef(
                    type: KnowledgeSourceType::Manual,
                    fingerprint: KnowledgeSourceRef::hash('manual', (string) $conversation->id, 'legacy-person'),
                    confidence: KnowledgeConfidence::manual(),
                    conversationId: $conversation->id,
                    manual: true,
                ),
            );

            $fallback = app(GetPersonStatusTool::class)->execute(
                new ToolCall('s2', GetPersonStatusTool::NAME, ['person' => $knowledgeName]),
                $context,
            );
            $this->assertTrue($fallback->success);
            $this->assertNotSame('directory', $fallback->payload['source'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_regular_user_cannot_use_directory_tools(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Denied');
            $registry = app(ToolRegistry::class);
            $denied = $registry->execute(
                new ToolCall('d1', FindPersonTool::NAME, ['query' => 'Sonia']),
                new ToolExecutionContext($user, $conversation),
            );
            $this->assertFalse($denied->success);
            $this->assertSame('tool_not_available', $denied->payload['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
