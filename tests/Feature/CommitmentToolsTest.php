<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\ToolConfirmationDecision;
use App\Enums\UserRole;
use App\Models\Person;
use App\Models\User;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Commitments\CommitmentService;
use App\Services\Conversations\ConversationService;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Tools\Commitments\CancelCommitmentTool;
use App\Services\Tools\Commitments\ConfirmCommitmentTool;
use App\Services\Tools\Commitments\CreateManualCommitmentTool;
use App\Services\Tools\Commitments\FindCommitmentTool;
use App\Services\Tools\Commitments\GetCommitmentTool;
use App\Services\Tools\Commitments\MarkCommitmentConfirmedTool;
use App\Services\Tools\Commitments\UpdateCommitmentDeadlineTool;
use App\Services\Tools\Synthesis\GetPersonStatusTool;
use App\Services\Tools\Synthesis\ListCommitmentsTool;
use App\Services\Tools\ToolConfirmationPolicy;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\Support\RestoresAiRoleSettings;
use Tests\TestCase;

class CommitmentToolsTest extends TestCase
{
    use CleansTemporaryJarvisRecords;
    use RestoresAiRoleSettings;

    public function test_list_find_get_read_first_class_and_person_status_uses_them(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Sergiy '.Str::random(4));
            $commitment = app(CommitmentService::class)->createManual($user, [
                'title' => 'Send Chicago budget',
                'person_id' => $person->id,
            ]);
            $conversation = app(ConversationService::class)->createPersonal($user, 'Commitments');
            $context = new ToolExecutionContext($user, $conversation);

            $listed = app(ListCommitmentsTool::class)->execute(new ToolCall('c1', ListCommitmentsTool::NAME, ['mode' => 'open']), $context);
            $this->assertTrue($listed->success);
            $this->assertSame('first_class', $listed->payload['source']);
            $this->assertSame($commitment->id, $listed->payload['commitments'][0]['id']);

            $found = app(FindCommitmentTool::class)->execute(new ToolCall('c2', FindCommitmentTool::NAME, ['query' => 'Chicago']), $context);
            $this->assertTrue($found->success);
            $this->assertSame($commitment->id, $found->payload['commitments'][0]['id']);

            $loaded = app(GetCommitmentTool::class)->execute(new ToolCall('c3', GetCommitmentTool::NAME, ['commitment_id' => $commitment->id]), $context);
            $this->assertTrue($loaded->success);
            $this->assertSame('Send Chicago budget', $loaded->payload['commitment']['title']);

            $status = app(GetPersonStatusTool::class)->execute(new ToolCall('p1', GetPersonStatusTool::NAME, ['person_id' => $person->id]), $context);
            $this->assertTrue($status->success);
            $this->assertSame($commitment->id, $status->payload['commitments'][0]['id']);
            $this->assertArrayHasKey('projects', $status->payload);

            $this->assertContains(ListCommitmentsTool::NAME, array_map(
                static fn ($tool) => $tool->name,
                app(ToolRegistry::class)->definitionsFor($context),
            ));
            $this->assertContains(FindCommitmentTool::NAME, array_map(
                static fn ($tool) => $tool->name,
                app(ToolRegistry::class)->definitionsFor($context),
            ));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_mutating_tools_require_confirmation_without_explicit_owner_intent(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $conversation = app(ConversationService::class)->createPersonal($user, 'Mutate');
            $context = new ToolExecutionContext($user, $conversation);
            $policy = app(ToolConfirmationPolicy::class);

            foreach ([
                app(CreateManualCommitmentTool::class),
                app(ConfirmCommitmentTool::class),
                app(MarkCommitmentConfirmedTool::class),
                app(CancelCommitmentTool::class),
                app(UpdateCommitmentDeadlineTool::class),
            ] as $tool) {
                $decision = $policy->decide($tool, $context, new ToolCall('x', $tool->name(), ['title' => 'x', 'commitment_id' => 1]));
                $this->assertSame(ToolConfirmationDecision::ConfirmationRequired, $decision, $tool->name());
            }

            $allowed = $policy->decide(
                app(CreateManualCommitmentTool::class),
                new ToolExecutionContext($user, $conversation, explicitUserCommand: true),
                new ToolCall('y', CreateManualCommitmentTool::NAME, ['title' => 'Send file']),
            );
            $this->assertSame(ToolConfirmationDecision::Allowed, $allowed);

            $created = app(CreateManualCommitmentTool::class)->execute(
                new ToolCall('z', CreateManualCommitmentTool::NAME, ['title' => 'Owner asked this']),
                new ToolExecutionContext($user, $conversation, explicitUserCommand: true),
            );
            $this->assertTrue($created->success);
            $this->assertSame(CommitmentEffectiveStatus::Open, CommitmentEffectiveStatus::tryFromLoose($created->payload['commitment']['status']));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_legacy_fallback_is_skipped_when_first_class_exists(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            app(CommitmentService::class)->createManual($user, ['title' => 'Operational promise']);
            $conversation = app(ConversationService::class)->createPersonal($user, 'Legacy');
            $listed = app(ListCommitmentsTool::class)->execute(
                new ToolCall('c1', ListCommitmentsTool::NAME, ['mode' => 'all']),
                new ToolExecutionContext($user, $conversation),
            );
            $this->assertSame('first_class', $listed->payload['source']);
            foreach ($listed->payload['commitments'] as $row) {
                $this->assertArrayHasKey('id', $row);
                $this->assertArrayNotHasKey('kind', $row);
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function person(User $user, string $name): Person
    {
        return Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => $name,
            'normalized_name' => ProjectNameNormalizer::normalize($name),
        ]);
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
