<?php

namespace Tests\Feature;

use App\Enums\AutomationRunOutcome;
use App\Enums\AutomationType;
use App\Enums\CommitmentEffectiveStatus;
use App\Enums\CommitmentLifecycleStatus;
use App\Enums\IntegrationAccountStatus;
use App\Enums\JarvisNotificationType;
use App\Enums\MeetingAnalysisStatus;
use App\Enums\OperationalEventType;
use App\Enums\OperationalSeverity;
use App\Enums\PersonIdentityType;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Enums\SourceItemType;
use App\Enums\UserRole;
use App\Models\AutomationRun;
use App\Models\Commitment;
use App\Models\IntegrationAccount;
use App\Models\JarvisNotification;
use App\Models\Meeting;
use App\Models\MeetingAnalysis;
use App\Models\OperationalEvent;
use App\Models\Person;
use App\Models\PersonIdentity;
use App\Models\ProactiveProposal;
use App\Models\SourceItem;
use App\Models\User;
use App\Models\UserProductivitySetting;
use App\Services\Automation\ExternalActionPolicy;
use App\Services\OperationalControl\OperationalControlScanService;
use App\Services\OperationalControl\ProactiveProposalExecutor;
use App\Services\Projects\ProjectNameNormalizer;
use App\Services\Projects\ProjectService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Illuminate\Support\Str;
use Tests\Support\CleansTemporaryJarvisRecords;
use Tests\TestCase;

class OperationalControlTest extends TestCase
{
    use CleansTemporaryJarvisRecords;

    public function test_overdue_commitment_creates_one_event_and_remind_proposal(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            $commitment = $this->overdueCommitment($user, $person, now()->subHours(30));

            $scan = app(OperationalControlScanService::class);
            $scan->scanUser($user);
            $scan->scanUser($user);

            $this->assertSame(1, OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::CommitmentOverdue)->count());
            $this->assertSame(1, ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->count());
            $this->assertSame(OperationalSeverity::High, OperationalEvent::query()->where('user_id', $user->id)->first()?->severity);
            $this->assertSame($commitment->id, ProactiveProposal::query()->where('user_id', $user->id)->first()?->commitment_id);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_likely_done_creates_confirm_proposal(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            Commitment::factory()->create([
                'user_id' => $user->id,
                'person_id' => $person->id,
                'title' => 'Final budget',
                'status' => CommitmentEffectiveStatus::LikelyDone,
                'lifecycle_status' => CommitmentLifecycleStatus::LikelyDone,
            ]);

            app(OperationalControlScanService::class)->scanUser($user);

            $proposal = ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::ConfirmCommitment)->first();
            $this->assertNotNull($proposal);
            $this->assertSame(ProactiveProposalStatus::Pending, $proposal->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_meeting_risk_creates_event(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $meeting = Meeting::factory()->create([
                'user_id' => $user->id,
                'title' => 'Budget review',
                'analysis_status' => MeetingAnalysisStatus::Completed,
            ]);
            $analysis = MeetingAnalysis::query()->create([
                'meeting_id' => $meeting->id,
                'version' => 1,
                'status' => MeetingAnalysisStatus::Completed,
                'result_json' => ['risks' => [['text' => 'Deadline today']], 'action_items' => [], 'decisions' => []],
            ]);
            $meeting->forceFill(['current_analysis_id' => $analysis->id])->save();

            app(OperationalControlScanService::class)->scanUser($user);

            $this->assertTrue(OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::MeetingRiskDetected)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_blocked_integration_creates_reconnect_proposal_once(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'external_account_id' => 'finance-'.$user->id,
                'display_label' => 'Finance mailbox',
                'status' => IntegrationAccountStatus::Error,
                'last_error_code' => 'blocked_auth',
            ]);

            $scan = app(OperationalControlScanService::class);
            $scan->scanUser($user);
            $scan->scanUser($user);

            $this->assertSame(1, ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::ReconnectIntegration)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_repeated_automation_failure_requires_threshold(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            AutomationRun::factory()->count(2)->sequence(
                ['run_key' => 'exec:'.$user->id.':a'],
                ['run_key' => 'exec:'.$user->id.':b'],
            )->create([
                'user_id' => $user->id,
                'automation_type' => AutomationType::ExecutiveBrief,
                'automation_id' => 7,
                'status' => AutomationRunOutcome::Failed,
            ]);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertFalse(OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::AutomationRepeatedFailure)->exists());

            AutomationRun::factory()->create([
                'user_id' => $user->id,
                'automation_type' => AutomationType::ExecutiveBrief,
                'automation_id' => 7,
                'status' => AutomationRunOutcome::Failed,
                'run_key' => 'exec:'.$user->id.':c',
            ]);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertTrue(OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::AutomationRepeatedFailure)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_execute_external_denied_by_default_and_unresolved_identity_blocks_send(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            $this->overdueCommitment($user, $person, now()->subHours(30));
            app(OperationalControlScanService::class)->scanUser($user);
            $proposal = ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->first();
            $this->assertNotNull($proposal);

            $executor = app(ProactiveProposalExecutor::class);
            $default = $executor->approve($user, $proposal->fresh());
            $this->assertTrue($default['ok']);
            $this->assertFalse($default['sent']);
            $this->assertNotNull($default['reminder_id']);

            $personB = $this->person($user, 'Marina');
            $this->overdueCommitment($user, $personB, now()->subHours(30), 'Other file');
            app(OperationalControlScanService::class)->scanUser($user);
            $second = ProactiveProposal::query()
                ->where('user_id', $user->id)
                ->where('person_id', $personB->id)
                ->where('status', ProactiveProposalStatus::Pending)
                ->first();
            $this->assertNotNull($second);

            UserProductivitySetting::query()->updateOrCreate(
                ['user_id' => $user->id],
                ['third_party_execute' => true, 'operational_alerts_enabled' => true],
            );
            $blocked = $executor->approve($user, $second->fresh(), [], true);
            $this->assertFalse($blocked['ok']);
            $this->assertSame('unresolved_identity', $blocked['error']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_stale_remind_proposal_is_not_sent_after_commitment_confirmed(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            $commitment = $this->overdueCommitment($user, $person, now()->subHours(30));
            app(OperationalControlScanService::class)->scanUser($user);
            $proposal = ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->first();

            $commitment->forceFill([
                'lifecycle_status' => CommitmentLifecycleStatus::Confirmed,
                'status' => CommitmentEffectiveStatus::Confirmed,
            ])->save();

            $result = app(ProactiveProposalExecutor::class)->approve($user, $proposal->fresh());
            $this->assertFalse($result['ok']);
            $this->assertSame('stale', $result['error']);
            $this->assertFalse($result['sent']);
            $this->assertSame(ProactiveProposalStatus::Expired, $proposal->fresh()->status);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_quiet_hours_cap_cooldown_and_severity_escalation(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $user->forceFill(['timezone' => 'UTC'])->save();
            UserProductivitySetting::query()->updateOrCreate(['user_id' => $user->id], [
                'operational_alerts_enabled' => true,
                'operational_min_severity' => 'high',
                'operational_max_alerts_per_day' => 1,
                'quiet_hours_start' => '00:00',
                'quiet_hours_end' => '23:59',
                'critical_bypass_quiet_hours' => true,
            ]);
            $person = $this->person($user, 'Serhii');
            $this->overdueCommitment($user, $person, now()->subHours(30));
            app(OperationalControlScanService::class)->scanUser($user);

            $this->assertSame(0, JarvisNotification::query()->where('user_id', $user->id)->where('type', JarvisNotificationType::OperationalAlert)->count());

            IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'external_account_id' => 'core-'.$user->id,
                'display_label' => 'Finance mailbox',
                'status' => IntegrationAccountStatus::Error,
                'last_error_code' => 'blocked_auth',
            ]);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('type', JarvisNotificationType::OperationalAlert)->count());

            $secondPerson = $this->person($user, 'Oksana');
            $this->overdueCommitment($user, $secondPerson, now()->subHours(80), 'Late deck');
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('type', JarvisNotificationType::OperationalAlert)->count());

            $event = OperationalEvent::query()->where('user_id', $user->id)->where('commitment_id', $this->overdueCommitmentId($user, 'Late deck'))->first();
            $this->assertSame(OperationalSeverity::Critical, $event?->severity);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_blocked_mailbox_does_not_infer_no_reply(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            $project = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
            $account = IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'external_account_id' => 'blocked-mail-'.$user->id,
                'status' => IntegrationAccountStatus::Error,
                'last_error_code' => 'blocked_auth',
            ]);
            SourceItem::query()->create([
                'user_id' => $user->id,
                'integration_account_id' => $account->id,
                'source_type' => SourceItemType::GmailMessage,
                'source_instance' => 'integration:'.$account->id,
                'external_id' => 'm-1',
                'thread_id' => 't-1',
                'occurred_at' => now()->subDays(2),
                'person_id' => $person->id,
                'project_id' => $project->id,
                'subject' => 'Need the file?',
                'snippet' => 'Can you send the budget?',
                'metadata' => ['explicit_request' => true, 'thread_has_reply' => false],
            ]);

            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertFalse(OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::EmailReplyExpected)->exists());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_cross_source_progress_is_not_completion_and_delivery_confirms_one_proposal(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            $commitment = $this->overdueCommitment($user, $person, now()->subHours(30));
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertTrue(ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->where('status', ProactiveProposalStatus::Pending)->exists());

            SourceItem::query()->create([
                'user_id' => $user->id,
                'source_type' => SourceItemType::TelegramMessage,
                'source_instance' => 'telegram_group:1',
                'external_id' => 'tg-1',
                'occurred_at' => now()->subHour(),
                'person_id' => $person->id,
                'snippet' => 'almost done',
                'metadata' => ['progress' => true],
            ]);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertSame(CommitmentLifecycleStatus::Open, $commitment->fresh()->lifecycle_status);
            $this->assertFalse(ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::ConfirmCommitment)->exists());

            $commitment->forceFill([
                'lifecycle_status' => CommitmentLifecycleStatus::LikelyDone,
                'status' => CommitmentEffectiveStatus::LikelyDone,
            ])->save();
            app(OperationalControlScanService::class)->scanUser($user);

            $this->assertSame(1, ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::ConfirmCommitment)->count());
            $remind = ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->first();
            $this->assertContains($remind->status, [ProactiveProposalStatus::Expired, ProactiveProposalStatus::Pending]);
            if ($remind->status === ProactiveProposalStatus::Pending) {
                $result = app(ProactiveProposalExecutor::class)->approve($user, $remind);
                $this->assertFalse($result['sent']);
            }
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_suggest_is_allowed_and_policy_execute_requires_setting(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $policy = app(ExternalActionPolicy::class);
            $this->assertFalse($policy->allowsExecute($user, 'notify_person'));
            $this->assertSame('suggest', $policy->levelFor($user, 'notify_person')->value);

            UserProductivitySetting::query()->updateOrCreate(['user_id' => $user->id], ['third_party_execute' => true]);
            $user->unsetRelation('productivitySetting');
            $this->assertTrue($policy->allowsExecute($user, 'notify_person'));
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_telegram_owner_alert_is_compact_with_deep_link_and_retry_does_not_duplicate(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $this->createTemporaryTelegramIdentity($user, '90001');
            $fake = new class implements SendsReminderTelegram
            {
                /** @var list<array{chatId: string, text: string, start: ?string}> */
                public array $sent = [];

                public function send(string $chatId, string $text, ?string $webAppStartParam = null): void
                {
                    $this->sent[] = ['chatId' => $chatId, 'text' => $text, 'start' => $webAppStartParam];
                }
            };
            $this->app->instance(SendsReminderTelegram::class, $fake);

            IntegrationAccount::query()->create([
                'user_id' => $user->id,
                'provider' => 'google',
                'external_account_id' => 'finance-'.$user->id,
                'display_label' => 'Finance mailbox',
                'status' => IntegrationAccountStatus::Error,
                'last_error_code' => 'blocked_auth',
            ]);
            $scan = app(OperationalControlScanService::class);
            $scan->scanUser($user);
            $scan->scanUser($user);

            $this->assertCount(1, $fake->sent);
            $this->assertStringContainsString('/lavr/proactive/', $fake->sent[0]['text']);
            $this->assertNotNull($fake->sent[0]['start']);
            $this->assertSame(1, JarvisNotification::query()->where('user_id', $user->id)->where('type', JarvisNotificationType::OperationalAlert)->count());
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_explicit_send_with_unique_identity_attempts_telegram(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $person = $this->person($user, 'Serhii');
            PersonIdentity::factory()->create([
                'person_id' => $person->id,
                'type' => PersonIdentityType::TelegramUserId,
                'value' => '555',
                'normalized_value' => '555',
            ]);
            $this->overdueCommitment($user, $person, now()->subHours(30));
            app(OperationalControlScanService::class)->scanUser($user);
            $proposal = ProactiveProposal::query()->where('user_id', $user->id)->where('proposal_type', ProactiveProposalType::RemindPerson)->first();

            $fake = new class implements SendsReminderTelegram
            {
                public array $sent = [];

                public function send(string $chatId, string $text, ?string $webAppStartParam = null): void
                {
                    $this->sent[] = ['chatId' => $chatId, 'text' => $text, 'start' => $webAppStartParam];
                }
            };
            $this->app->instance(SendsReminderTelegram::class, $fake);

            $result = app(ProactiveProposalExecutor::class)->approve($user, $proposal->fresh(), [], true);
            $this->assertTrue($result['ok']);
            $this->assertTrue($result['sent']);
            $this->assertSame('555', $fake->sent[0]['chatId']);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    public function test_project_stale_requires_active_obligation_and_blocker(): void
    {
        $user = null;

        try {
            $user = $this->temporaryOwner();
            $quiet = app(ProjectService::class)->create($user, 'Quiet '.Str::random(4), null);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertFalse(OperationalEvent::query()->where('user_id', $user->id)->where('event_type', OperationalEventType::ProjectStale)->exists());

            $person = $this->person($user, 'Serhii');
            $busy = app(ProjectService::class)->create($user, 'Chicago '.Str::random(4), null);
            $this->overdueCommitment($user, $person, now()->subDays(10), 'Budget', $busy->id);
            app(OperationalControlScanService::class)->scanUser($user);
            $this->assertTrue(OperationalEvent::query()->where('user_id', $user->id)->where('project_id', $busy->id)->where('event_type', OperationalEventType::ProjectStale)->exists());
            unset($quiet);
        } finally {
            $this->deleteTemporaryUser($user);
        }
    }

    private function overdueCommitment(User $user, Person $person, mixed $deadline, string $title = 'Final budget', ?int $projectId = null): Commitment
    {
        return Commitment::factory()->create([
            'user_id' => $user->id,
            'person_id' => $person->id,
            'project_id' => $projectId,
            'title' => $title,
            'status' => CommitmentEffectiveStatus::Overdue,
            'lifecycle_status' => CommitmentLifecycleStatus::Open,
            'deadline_at' => $deadline,
        ]);
    }

    private function overdueCommitmentId(User $user, string $title): int
    {
        return (int) Commitment::query()->where('user_id', $user->id)->where('title', $title)->value('id');
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
