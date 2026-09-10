<?php

namespace Tests\Feature\Automation;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\UserRole;
use App\Models\AutomationRun;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class AutomationRunsAdminTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_owner_can_filter_automation_runs(): void
    {
        $user = null;

        try {
            $user = $this->owner();
            AutomationRun::query()->create([
                'user_id' => $user->id,
                'automation_type' => AutomationType::Watcher,
                'automation_id' => 9,
                'run_key' => 'watcher:9:poll:2026-09-10T10:15',
                'status' => AutomationRunOutcome::Failed,
                'attempt' => 1,
                'started_at' => now(),
                'outcome_code' => 'blocked_auth',
                'safe_error' => 'blocked_auth',
            ]);

            $page = $this->inertia($this->actingAs($user)
                ->get(route('automation-runs.index', ['failed' => 1, 'type' => 'watcher'])));

            $this->assertSame('AutomationRuns/Index', $page['component'] ?? null);
            $this->assertCount(1, $page['props']['runs'] ?? []);
            $this->assertSame('watcher:9:poll:2026-09-10T10:15', $page['props']['runs'][0]['run_key'] ?? null);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_regular_user_cannot_open_automation_runs(): void
    {
        $user = null;

        try {
            $user = $this->createTemporaryUser();
            $this->actingAs($user)->get(route('automation-runs.index'))->assertForbidden();
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function owner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner, 'timezone' => 'Europe/Rome'])->save();

        return $user;
    }

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
}
