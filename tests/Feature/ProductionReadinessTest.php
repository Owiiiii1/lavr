<?php

namespace Tests\Feature;

use App\Enums\AiRoleKey;
use App\Enums\CommitmentConfidence;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentEvidenceType;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\CommitmentSourceType;
use App\Enums\IntegrationAccountStatus;
use App\Enums\OnboardingStatus;
use App\Enums\SourceItemType;
use App\Enums\UserRole;
use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Models\Commitment;
use App\Models\CommitmentEvidence;
use App\Models\IntegrationAccount;
use App\Models\Person;
use App\Models\Project;
use App\Models\ProjectSourceBinding;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\UserAssistantProfile;
use App\Services\Handover\HandoverCleanupService;
use App\Services\Onboarding\BusinessMapService;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use App\Services\Readiness\HeartbeatRecorder;
use App\Services\Readiness\LavrDiagnosticsService;
use App\Services\Validation\ValidationSeedService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_diagnostics_reports_stale_queue_blocked_google_and_missing_ai(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            Cache::forget((string) config('readiness.heartbeat_cache_key'));
            Cache::forget((string) config('readiness.queue_heartbeat_cache_key'));
            IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'external_account_id' => 'blocked-'.$user->id,
                'display_label' => 'Finance',
                'status' => IntegrationAccountStatus::Error,
                'health' => 'blocked',
            ]);

            $snapshot = app(LavrDiagnosticsService::class)->snapshot($user);
            $this->assertSame('not_configured', $snapshot['checks']['scheduler']['status']);
            $this->assertSame('not_configured', $snapshot['checks']['queue']['status']);
            $this->assertSame('blocked', $snapshot['checks']['google']['status']);
            $this->assertArrayHasKey('ai', $snapshot['checks']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_heartbeat_marks_scheduler_and_queue_healthy(): void
    {
        Cache::forget((string) config('readiness.heartbeat_cache_key'));
        Cache::forget((string) config('readiness.queue_heartbeat_cache_key'));
        app(HeartbeatRecorder::class)->recordScheduler();
        $snapshot = app(LavrDiagnosticsService::class)->snapshot();
        $this->assertSame('healthy', $snapshot['checks']['scheduler']['status']);
        $this->assertSame('not_configured', $snapshot['checks']['queue']['status']);

        app(HeartbeatRecorder::class)->recordQueue();
        $snapshot = app(LavrDiagnosticsService::class)->snapshot();
        $this->assertSame('healthy', $snapshot['checks']['queue']['status']);
    }

    public function test_ai_health_uses_enabled_owner_role_instead_of_legacy_active_provider_flag(): void
    {
        $provider = AiProviderSetting::query()->where('provider', 'gemini')->firstOrFail();
        $role = AiRoleSetting::query()->where('role_key', AiRoleKey::OwnerConversation->value)->firstOrFail();
        $providerSnapshot = $provider->only(['api_key', 'is_connected', 'is_active']);
        $roleSnapshot = $role->only(['provider', 'model', 'is_enabled']);

        try {
            $provider->forceFill([
                'api_key' => 'synthetic-readiness-key',
                'is_connected' => true,
                'is_active' => false,
            ])->save();
            $role->forceFill([
                'provider' => 'gemini',
                'model' => 'gemini-3.7-flash',
                'is_enabled' => true,
            ])->save();

            $snapshot = app(LavrDiagnosticsService::class)->snapshot();

            $this->assertSame('healthy', $snapshot['checks']['ai']['status']);
            $this->assertSame('gemini-3.7-flash', $snapshot['checks']['ai']['model']);
        } finally {
            $provider->forceFill($providerSnapshot)->save();
            $role->forceFill($roleSnapshot)->save();
        }
    }

    public function test_handover_cleanup_without_selector_refuses(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->expectException(\InvalidArgumentException::class);
            app(HandoverCleanupService::class)->plan($user, []);
            $this->fail('plan() must refuse without a selector');
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_handover_dry_run_changes_zero_rows_and_preserves_canonical_commitment(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            [$accountA, $accountB, $commitment] = $this->seedIntegrationStory($user);
            $itemsBefore = SourceItem::query()->count();
            $plan = app(HandoverCleanupService::class)->run($user, ['integration' => $accountA->id], false);
            $this->assertTrue($plan['dry_run']);
            $this->assertSame($itemsBefore, SourceItem::query()->count());
            $this->assertNotNull(Commitment::query()->find($commitment->id));
            $this->assertNotNull($accountA->fresh()->credentials_encrypted);

            $executed = app(HandoverCleanupService::class)->run($user, ['integration' => $accountA->id], true, HandoverCleanupService::CONFIRM_TOKEN);
            $this->assertFalse($executed['dry_run']);
            $this->assertSame(0, SourceItem::query()->where('integration_account_id', $accountA->id)->count());
            $this->assertSame(0, ProjectSourceBinding::query()->where('source_id', $accountA->id)->count());
            $this->assertNotNull(Commitment::query()->find($commitment->id));
            $this->assertTrue(CommitmentEvidence::query()->where('commitment_id', $commitment->id)->where('source_id', $accountB->id)->exists());
            $this->assertFalse(CommitmentEvidence::query()->where('commitment_id', $commitment->id)->where('source_id', $accountA->id)->exists());
            $this->assertNull($accountA->fresh()->credentials_encrypted);
            $this->assertStringNotContainsString('secret-token', json_encode($executed));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_validation_batch_cleanup_removes_only_batch_data(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $keep = Person::factory()->create([
                'user_id' => $user->id,
                'display_name' => 'Keep Me',
                'normalized_name' => ProjectNameNormalizer::normalize('Keep Me'),
            ]);
            $seed = app(ValidationSeedService::class)->seed($user, true);
            $this->assertNotEmpty($seed['marker']);
            $peopleBeforeCleanup = Person::query()->where('user_id', $user->id)->count();
            $this->assertGreaterThan(1, $peopleBeforeCleanup);

            app(HandoverCleanupService::class)->run($user, ['batch' => $seed['marker']], true, HandoverCleanupService::CONFIRM_TOKEN);
            $this->assertTrue(Person::query()->whereKey($keep->id)->exists());
            $this->assertFalse(Person::query()->where('user_id', $user->id)->where('display_name', 'like', '[VAL]%')->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_existing_owner_legacy_onboarding_stays_complete_and_setup_resumes(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            UserAssistantProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'assistant_name' => 'LAVR',
                    'onboarding_status' => OnboardingStatus::Completed,
                    'onboarding_completed_at' => now(),
                ],
            );

            $this->actingAs($user)->get('/lavr/setup')->assertOk();
            $this->actingAs($user)->post('/lavr/setup', [
                'step' => 'owner_profile',
                'name' => 'Owner',
                'timezone' => 'Europe/Kyiv',
                'interface_locale' => 'uk',
                'assistant_locale' => 'uk',
                'assistant_name' => 'LAVR',
            ])->assertRedirect();

            $this->assertSame(OnboardingStatus::Completed, $user->assistantProfile()->first()?->onboarding_status);
            $map = app(BusinessMapService::class)->snapshot($user->fresh());
            $this->assertTrue(collect($map['steps'])->firstWhere('key', 'owner_profile')['done']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_setup_creates_canonical_project_and_person(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->actingAs($user)->post('/lavr/setup', [
                'step' => 'business_contexts',
                'project_name' => 'Chicago',
            ])->assertRedirect();
            $this->actingAs($user)->post('/lavr/setup', [
                'step' => 'key_people',
                'display_name' => 'Serhii',
            ])->assertRedirect();

            $this->assertTrue(Project::query()->where('user_id', $user->id)->where('name', 'Chicago')->exists());
            $this->assertTrue(Person::query()->where('user_id', $user->id)->where('display_name', 'Serhii')->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_health_and_smoke_and_system_health_page(): void
    {
        $owner = User::query()->where('role', UserRole::Owner)->first();
        $this->assertNotNull($owner);
        app(HeartbeatRecorder::class)->recordScheduler();
        $this->actingAs($owner)->get('/lavr/system-health')->assertOk();
        $this->actingAs($owner)->get('/production-readiness')->assertOk();
        $this->assertSame(0, Artisan::call('lavr:production-smoke'));
        $this->assertSame(0, Artisan::call('lavr:diagnostics', ['--json' => true]));
        $this->assertSame(0, Artisan::call('lavr:backup', ['--dry-run' => '1']));
        $this->assertNotSame(0, Artisan::call('lavr:handover-cleanup'));
    }

    /**
     * @return array{0: IntegrationAccount, 1: IntegrationAccount, 2: Commitment}
     */
    private function seedIntegrationStory(User $user): array
    {
        $person = Person::factory()->create([
            'user_id' => $user->id,
            'display_name' => 'Serhii',
            'normalized_name' => ProjectNameNormalizer::normalize('Serhii'),
        ]);
        $project = app(ProjectService::class)->create($user, 'Chicago '.$user->id);
        $accountA = IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'external_account_id' => 'a-'.$user->id,
            'display_label' => 'developer@gmail.com',
            'status' => IntegrationAccountStatus::Connected,
            'credentials_encrypted' => ['access_token' => 'secret-token'],
        ]);
        $accountB = IntegrationAccount::query()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'external_account_id' => 'b-'.$user->id,
            'display_label' => 'client@gmail.com',
            'status' => IntegrationAccountStatus::Connected,
        ]);
        SourceItem::query()->create([
            'user_id' => $user->id,
            'integration_account_id' => $accountA->id,
            'source_type' => SourceItemType::GmailMessage,
            'source_instance' => 'integration:'.$accountA->id,
            'external_id' => 'a-1',
            'occurred_at' => now(),
        ]);
        ProjectSourceBinding::query()->create([
            'project_id' => $project->id,
            'source_type' => 'google_mailbox',
            'source_id' => $accountA->id,
            'binding_kind' => 'explicit',
        ]);
        $commitment = Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'project_id' => $project->id,
            'title' => 'Final budget',
            'status' => CommitmentEffectiveStatus::Confirmed,
            'lifecycle_status' => CommitmentLifecycleStatus::Confirmed,
            'source_type' => CommitmentSourceType::Email,
            'source_id' => $accountA->id,
        ]);
        CommitmentEvidence::query()->create([
            'commitment_id' => $commitment->id,
            'evidence_type' => CommitmentEvidenceType::Promise,
            'source_type' => CommitmentSourceType::Email,
            'source_id' => $accountA->id,
            'observed_at' => now(),
            'confidence' => CommitmentConfidence::High,
        ]);
        CommitmentEvidence::query()->create([
            'commitment_id' => $commitment->id,
            'evidence_type' => CommitmentEvidenceType::Delivery,
            'source_type' => CommitmentSourceType::Other,
            'source_id' => $accountB->id,
            'observed_at' => now(),
            'confidence' => CommitmentConfidence::High,
        ]);

        return [$accountA, $accountB, $commitment];
    }

    private function temporaryOwner(): User
    {
        $user = $this->createTemporaryUser();
        $user->forceFill(['role' => UserRole::Owner])->save();

        return $user;
    }
}
