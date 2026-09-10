<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\UserRole;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Person;
use App\Models\User;
use App\Services\Commitments\CommitmentService;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class CommitmentWorkspaceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_and_regular_user_cannot_open_commitments(): void
    {
        $user = null;

        try {
            $this->get(route('jarvis.commitments.index'))->assertRedirect(route('login'));
            $this->get(route('commitments.index'))->assertRedirect();
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('jarvis.commitments.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_can_list_create_filter_and_open_detail(): void
    {
        $user = null;
        $other = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Test Manager');
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
            $service = app(CommitmentService::class);
            $commitment = $service->createManual($user, [
                'title' => 'Send final budget',
                'person_id' => $person->id,
                'project_id' => $project->id,
            ]);

            $list = $this->inertia($this->actingAs($user)->get(route('jarvis.commitments.index')));
            $this->assertSame('Jarvis/Commitments', $list['component']);
            $this->assertNotEmpty($list['props']['sections']['open']);

            $admin = $this->inertia($this->actingAs($user)->get(route('commitments.index', ['status' => 'open', 'person_id' => $person->id])));
            $this->assertSame('Commitments/Index', $admin['component']);
            $this->assertSame($commitment->id, $admin['props']['commitments'][0]['id']);

            $show = $this->inertia($this->actingAs($user)->get(route('jarvis.commitments.show', $commitment)));
            $this->assertSame('Jarvis/CommitmentShow', $show['component']);
            $this->assertSame('Send final budget', $show['props']['commitment']['title']);

            $other = $this->createTemporaryUser();
            $foreign = Commitment::factory()->create(['user_id' => $other->id]);
            $this->actingAs($user)->get(route('jarvis.commitments.show', $foreign))->assertNotFound();
        } finally {
            $this->deleteTemporaryUser($user);
            $this->deleteTemporaryUser($other);
        }
    }

    public function test_today_person_project_and_meeting_surface_commitments(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Sergiy');
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
            $meeting = Meeting::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Budget call']);
            $overdue = Commitment::factory()->create([
                'user_id' => $user->id,
                'person_id' => $person->id,
                'project_id' => $project->id,
                'meeting_id' => $meeting->id,
                'title' => 'Send budget',
                'status' => CommitmentEffectiveStatus::Overdue,
                'lifecycle_status' => CommitmentLifecycleStatus::Open,
                'deadline_at' => now()->subDay(),
            ]);

            $today = $this->inertia($this->actingAs($user)->get(route('jarvis.today.show')));
            $this->assertSame('Jarvis/Today', $today['component']);
            $this->assertSame($overdue->id, $today['props']['today']['commitments'][0]['id']);

            $personPage = $this->inertia($this->actingAs($user)->get(route('jarvis.people.show', $person)));
            $this->assertSame($overdue->id, $personPage['props']['commitments'][0]['id']);

            $projectPage = $this->inertia($this->actingAs($user)->get(route('jarvis.workspace.projects.show', $project)));
            $this->assertSame($overdue->id, $projectPage['props']['commitments'][0]['id']);

            $this->assertStringContainsString('section_overdue', file_get_contents(base_path('resources/js/locales/uk.js')));
            $this->assertStringContainsString('section_overdue', file_get_contents(base_path('resources/js/locales/en.js')));
            $this->assertStringContainsString('section_overdue', file_get_contents(base_path('resources/js/locales/ru.js')));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function inertia(TestResponse $response): array
    {
        $response->assertOk();
        $html = $response->getContent();
        $marker = '<script data-page="app" type="application/json">';
        $start = strpos($html, $marker);
        $this->assertNotFalse($start);
        $start += strlen($marker);
        $end = strpos($html, '</script>', $start);
        $this->assertNotFalse($end);
        $page = json_decode(substr($html, $start, $end - $start), true);
        $this->assertIsArray($page);

        return $page;
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
