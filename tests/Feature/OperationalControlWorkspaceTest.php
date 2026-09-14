<?php

namespace Tests\Feature;

use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\ProactiveProposalStatus;
use App\Enums\UserRole;
use App\Models\Commitment;
use App\Models\Person;
use App\Models\ProactiveProposal;
use App\Models\User;
use App\Services\OperationalControl\OperationalControlScanService;
use App\Services\Projects\ProjectNameNormalizer;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class OperationalControlWorkspaceTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_guest_and_regular_user_cannot_open_proactive_center(): void
    {
        $user = null;

        try {
            $this->get(route('jarvis.proactive.index'))->assertRedirect(route('login'));
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('jarvis.proactive.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_owner_can_open_center_approve_snooze_and_dismiss(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = Person::factory()->create([
                'user_id' => $user->id,
                'display_name' => 'Serhii',
                'normalized_name' => ProjectNameNormalizer::normalize('Serhii'),
            ]);
            Commitment::factory()->create([
                'user_id' => $user->id,
                'person_id' => $person->id,
                'title' => 'Final budget',
                'status' => CommitmentEffectiveStatus::Overdue,
                'lifecycle_status' => CommitmentLifecycleStatus::Open,
                'deadline_at' => now()->subHours(30),
            ]);
            app(OperationalControlScanService::class)->scanUser($user);
            $proposal = ProactiveProposal::query()->where('user_id', $user->id)->first();
            $this->assertNotNull($proposal);

            $page = $this->inertia($this->actingAs($user)->get(route('jarvis.proactive.index')));
            $this->assertSame('Jarvis/Proactive/Index', $page['component']);
            $this->assertNotEmpty($page['props']['needs_attention']);

            $show = $this->inertia($this->actingAs($user)->get(route('jarvis.proactive.show', $proposal)));
            $this->assertSame('Jarvis/Proactive/Show', $show['component']);
            $this->assertSame($proposal->id, $show['props']['proposal']['id']);

            $this->actingAs($user)->post(route('jarvis.proactive.snooze', $proposal), ['when' => 'tomorrow'])->assertRedirect();
            $this->assertNotNull($proposal->fresh()->snoozed_until);

            $proposal->forceFill(['snoozed_until' => null])->save();
            $this->actingAs($user)->post(route('jarvis.proactive.dismiss', $proposal), ['reason' => 'not_relevant'])->assertRedirect();
            $this->assertSame(ProactiveProposalStatus::Dismissed, $proposal->fresh()->status);

            $personPage = $this->inertia($this->actingAs($user)->get(route('jarvis.people.show', $person)));
            $this->assertArrayHasKey('proposals', $personPage['props']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_more_and_locales_include_proactive(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $page = $this->inertia($this->actingAs($user)->get(route('jarvis.more.show')));
            $this->assertSame('Jarvis/More', $page['component']);
            $html = $this->actingAs($user)->get(route('jarvis.proactive.index'))->assertOk()->getContent();
            $this->assertTrue(str_contains($html, 'proactive') || str_contains($html, 'Proactive') || str_contains($html, 'Проактив'));
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

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
