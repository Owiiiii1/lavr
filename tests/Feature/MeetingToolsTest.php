<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Conversations\ConversationService;
use App\Services\Meetings\MeetingService;
use App\Services\Tools\Meetings\FindMeetingTool;
use App\Services\Tools\Meetings\GetMeetingAnalysisTool;
use App\Services\Tools\Meetings\GetMeetingTool;
use App\Services\Tools\Meetings\ListMeetingsTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\FakeAiChatGateway;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class MeetingToolsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_owner_can_list_find_and_get_meeting_analysis(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->snapshotAiRoleSettings();
            $this->enableRoleForTests(AiRoleKey::OwnerAnalysis);
            $fake = new FakeAiChatGateway;
            $fake->analysisResponseText = json_encode([
                'summary' => ['executive' => 'Chicago locked lighting.', 'outcomes' => ['Keep vendor', 'Freeze list', 'Budget Friday'], 'attention' => []],
                'participants' => ['Sonia'],
                'topics' => ['Chicago'],
                'decisions' => [['text' => 'Keep lighting', 'confidence' => 'high', 'evidence' => ['excerpt' => 'keep vendor']]],
                'action_items' => [['task' => 'Send budget', 'status' => 'detected', 'confidence' => 'high', 'evidence' => ['excerpt' => 'budget']]],
                'commitments_detected' => [['person_name' => 'Sergiy', 'action' => 'send budget', 'confidence' => 'high', 'evidence' => ['excerpt' => 'Friday']]],
                'deadlines' => [['text' => 'Friday', 'confidence' => 'high', 'evidence' => ['excerpt' => 'Friday']]],
                'open_questions' => [['text' => 'Security?', 'confidence' => 'medium']],
                'risks' => [['text' => 'Freight late', 'confidence' => 'medium']],
                'follow_ups' => [['text' => 'Deposit', 'confidence' => 'low']],
                'unresolved_identities' => ['Speaker 3'],
            ], JSON_UNESCAPED_UNICODE);
            $this->app->instance(AiChatGateway::class, $fake);

            $meeting = app(MeetingService::class)->createManual($user, [
                'title' => 'Chicago '.Str::random(4),
            ], null, 'Sonia: Keep lighting. Sergiy: I will send the budget by Friday.');

            $conversation = app(ConversationService::class)->createPersonal($user, 'Meetings');
            $context = new ToolExecutionContext($user, $conversation);

            $listed = app(ListMeetingsTool::class)->execute(new ToolCall('m1', ListMeetingsTool::NAME, []), $context);
            $this->assertTrue($listed->success);
            $this->assertSame($meeting->id, $listed->payload['meetings'][0]['id']);

            $found = app(FindMeetingTool::class)->execute(new ToolCall('m2', FindMeetingTool::NAME, ['query' => 'Chicago']), $context);
            $this->assertTrue($found->success);

            $loaded = app(GetMeetingTool::class)->execute(new ToolCall('m3', GetMeetingTool::NAME, ['meeting_id' => $meeting->id]), $context);
            $this->assertTrue($loaded->success);
            $this->assertArrayNotHasKey('artifact', $loaded->payload['meeting']);

            $analysis = app(GetMeetingAnalysisTool::class)->execute(new ToolCall('m4', GetMeetingAnalysisTool::NAME, ['meeting_id' => $meeting->id]), $context);
            $this->assertTrue($analysis->success);
            $this->assertSame('Keep lighting', $analysis->payload['analysis']['result']['decisions'][0]['text']);
            $this->assertNotEmpty($analysis->payload['analysis']['result']['commitments_detected']);

            $regular = $this->createTemporaryUser();
            $denied = app(ToolRegistry::class)->execute(
                new ToolCall('d1', ListMeetingsTool::NAME, []),
                new ToolExecutionContext($regular, app(ConversationService::class)->createPersonal($regular, 'Denied')),
            );
            $this->assertFalse($denied->success);
            $this->deleteTemporaryUser($regular);
        } finally {
            $this->restoreAiRoleSettings();
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_registry_resolves_meeting_tools(): void
    {
        $registry = $this->app->make(ToolRegistry::class);
        $this->assertInstanceOf(ListMeetingsTool::class, $registry->resolve(ListMeetingsTool::NAME));
        $this->assertInstanceOf(GetMeetingAnalysisTool::class, $registry->resolve(GetMeetingAnalysisTool::NAME));
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
