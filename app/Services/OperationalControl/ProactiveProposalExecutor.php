<?php

namespace App\Services\OperationalControl;

use App\Enums\ProactiveAuditAction;
use App\Enums\ProactiveProposalStatus;
use App\Enums\ProactiveProposalType;
use App\Models\Commitment;
use App\Models\ProactiveProposal;
use App\Models\User;
use App\Services\Automation\ExternalActionPolicy;
use App\Services\Commitments\CommitmentService;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use App\Services\Reminders\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

final class ProactiveProposalExecutor
{
    public function __construct(
        private readonly ProactiveProposalReconciler $reconciler,
        private readonly ProactiveProposalService $proposals,
        private readonly OperationalIdentityGuard $identities,
        private readonly ExternalActionPolicy $policy,
        private readonly ProductivitySettingsService $settings,
        private readonly ReminderService $reminders,
        private readonly CommitmentService $commitments,
        private readonly ?SendsReminderTelegram $telegram = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    public function approve(User $user, ProactiveProposal $proposal, array $input = [], bool $explicitSend = false): array
    {
        if ((int) $proposal->user_id !== (int) $user->id) {
            return $this->fail($proposal, 'forbidden');
        }

        if ($proposal->status !== ProactiveProposalStatus::Pending) {
            return ['ok' => false, 'status' => (string) $proposal->status->value, 'error' => 'not_pending', 'reminder_id' => null, 'sent' => false];
        }

        if (! $this->reconciler->revalidateForExecute($proposal)) {
            $this->reconciler->supersede($proposal, 'stale_revalidation');

            return ['ok' => false, 'status' => ProactiveProposalStatus::Expired->value, 'error' => 'stale', 'reminder_id' => null, 'sent' => false];
        }

        if (isset($input['draft_body']) && is_string($input['draft_body'])) {
            $payload = is_array($proposal->action_payload_json) ? $proposal->action_payload_json : [];
            $payload['draft_body'] = mb_substr($input['draft_body'], 0, 2000);
            $payload['draft_status'] = 'draft';
            $proposal->forceFill(['action_payload_json' => $payload])->save();
            $this->proposals->audit($proposal, ProactiveAuditAction::Edited, 'edited');
        }

        $proposal->forceFill([
            'status' => ProactiveProposalStatus::Approved,
            'acted_at' => now(),
        ])->save();
        $this->proposals->audit($proposal, ProactiveAuditAction::Approved, 'approved');

        return $this->execute($user, $proposal->fresh() ?? $proposal, $explicitSend);
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    public function execute(User $user, ProactiveProposal $proposal, bool $explicitSend = false): array
    {
        if (! $this->reconciler->revalidateForExecute($proposal)) {
            $this->reconciler->supersede($proposal, 'stale_revalidation');

            return ['ok' => false, 'status' => ProactiveProposalStatus::Expired->value, 'error' => 'stale', 'reminder_id' => null, 'sent' => false];
        }

        $type = $proposal->proposal_type instanceof ProactiveProposalType
            ? $proposal->proposal_type
            : ProactiveProposalType::OpenSource;

        try {
            $result = match ($type) {
                ProactiveProposalType::RemindPerson, ProactiveProposalType::ScheduleFollowup => $this->remindOrSend($user, $proposal, $explicitSend),
                ProactiveProposalType::ConfirmCommitment => $this->confirmCommitment($user, $proposal),
                ProactiveProposalType::CreateReminder => $this->createOwnerReminder($user, $proposal),
                ProactiveProposalType::DraftEmail, ProactiveProposalType::DraftTelegramMessage => $this->storeDraft($proposal),
                default => ['ok' => true, 'status' => ProactiveProposalStatus::Executed->value, 'error' => null, 'reminder_id' => null, 'sent' => false],
            };
        } catch (\Throwable $exception) {
            $proposal->forceFill(['status' => ProactiveProposalStatus::Failed])->save();
            $this->proposals->audit($proposal, ProactiveAuditAction::Failed, 'failed');

            return ['ok' => false, 'status' => ProactiveProposalStatus::Failed->value, 'error' => 'execute_failed', 'reminder_id' => null, 'sent' => false];
        }

        if (($result['ok'] ?? false) === true) {
            $proposal->forceFill([
                'status' => ProactiveProposalStatus::Executed,
                'acted_at' => now(),
            ])->save();
            $this->proposals->audit(
                $proposal,
                ProactiveAuditAction::Executed,
                $result['sent'] ? 'sent' : 'executed',
                $proposal->commitment_id ? 'commitment' : ($proposal->person_id ? 'person' : null),
                $proposal->commitment_id ?? $proposal->person_id,
                $result['sent'] ? 'telegram' : null,
            );
        } elseif (($result['error'] ?? null) !== 'stale') {
            $proposal->forceFill(['status' => ProactiveProposalStatus::Failed])->save();
            $this->proposals->audit($proposal, ProactiveAuditAction::Failed, $result['error'] ?? 'failed');
        }

        return $result;
    }

    public function dismiss(User $user, ProactiveProposal $proposal, ?string $reason = null): ProactiveProposal
    {
        $proposal->forceFill([
            'status' => ProactiveProposalStatus::Dismissed,
            'dismiss_reason' => $reason,
            'acted_at' => now(),
        ])->save();
        $this->proposals->audit($proposal, ProactiveAuditAction::Dismissed, $reason ?: 'dismissed');

        if ($reason === 'dont_alert_this_type') {
            $this->disableRule($user, $proposal);
        }

        return $proposal->fresh() ?? $proposal;
    }

    public function snooze(User $user, ProactiveProposal $proposal, string $when): ProactiveProposal
    {
        $until = match ($when) {
            'later_today' => CarbonImmutable::now($user->timezone ?: 'UTC')->addHours(4),
            'tomorrow' => CarbonImmutable::now($user->timezone ?: 'UTC')->addDay()->setTime(9, 0),
            default => CarbonImmutable::parse($when, $user->timezone ?: 'UTC'),
        };

        $proposal->forceFill(['snoozed_until' => $until->utc()])->save();
        $this->proposals->audit($proposal, ProactiveAuditAction::Snoozed, 'snoozed');

        return $proposal->fresh() ?? $proposal;
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    private function remindOrSend(User $user, ProactiveProposal $proposal, bool $explicitSend): array
    {
        $settings = $this->settings->for($user);
        $wantsSend = $explicitSend || (bool) ($settings->third_party_execute ?? false);
        $action = 'notify_person';
        $policyAllows = $this->policy->allowsExecute($user, $action);

        if ($wantsSend && ($policyAllows || $explicitSend)) {
            $identity = $this->identities->resolve($proposal);
            if (! $identity['ok'] || $identity['telegram_user_id'] === null) {
                return ['ok' => false, 'status' => ProactiveProposalStatus::Failed->value, 'error' => $identity['reason'] ?? 'unresolved_identity', 'reminder_id' => null, 'sent' => false];
            }

            $rateKey = 'operational-outbound:'.$user->id;
            if (RateLimiter::tooManyAttempts($rateKey, (int) config('operational_control.outbound_per_hour', 3))) {
                return ['ok' => false, 'status' => ProactiveProposalStatus::Failed->value, 'error' => 'rate_limited', 'reminder_id' => null, 'sent' => false];
            }
            RateLimiter::hit($rateKey, 3600);

            $sent = $this->sendTelegram($proposal, $identity['telegram_user_id']);
            if (! $sent) {
                return ['ok' => false, 'status' => ProactiveProposalStatus::Failed->value, 'error' => 'send_failed', 'reminder_id' => null, 'sent' => false];
            }

            return ['ok' => true, 'status' => ProactiveProposalStatus::Executed->value, 'error' => null, 'reminder_id' => null, 'sent' => true];
        }

        $reminder = $this->createOwnerReminder($user, $proposal);

        return [
            'ok' => true,
            'status' => ProactiveProposalStatus::Executed->value,
            'error' => null,
            'reminder_id' => $reminder['reminder_id'],
            'sent' => false,
        ];
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    private function confirmCommitment(User $user, ProactiveProposal $proposal): array
    {
        if ($proposal->commitment_id === null) {
            return $this->fail($proposal, 'missing_commitment');
        }

        $commitment = Commitment::query()->find($proposal->commitment_id);
        if ($commitment === null) {
            return $this->fail($proposal, 'missing_commitment');
        }

        $this->commitments->markConfirmed($user, $commitment);

        return ['ok' => true, 'status' => ProactiveProposalStatus::Executed->value, 'error' => null, 'reminder_id' => null, 'sent' => false];
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    private function createOwnerReminder(User $user, ProactiveProposal $proposal): array
    {
        $timezone = (string) ($user->timezone ?: 'UTC');
        $runAt = CarbonImmutable::now($timezone)->addHour();
        $reminder = $this->reminders->create($user, $proposal->title, $runAt, $timezone);

        return ['ok' => true, 'status' => ProactiveProposalStatus::Executed->value, 'error' => null, 'reminder_id' => $reminder->id, 'sent' => false];
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    private function storeDraft(ProactiveProposal $proposal): array
    {
        $payload = is_array($proposal->action_payload_json) ? $proposal->action_payload_json : [];
        $payload['draft_status'] = 'draft';
        if (! isset($payload['draft_body'])) {
            $payload['draft_body'] = $proposal->rationale;
        }
        $proposal->forceFill(['action_payload_json' => $payload])->save();

        return ['ok' => true, 'status' => ProactiveProposalStatus::Executed->value, 'error' => null, 'reminder_id' => null, 'sent' => false];
    }

    private function sendTelegram(ProactiveProposal $proposal, ?string $chatId): bool
    {
        if ($this->telegram === null || $chatId === null || $chatId === '') {
            return false;
        }

        $lock = Cache::lock('operational-send:'.$proposal->id, 30);
        if (! $lock->get()) {
            return false;
        }

        try {
            $this->telegram->send($chatId, $proposal->title, 'proactive_'.$proposal->id);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function disableRule(User $user, ProactiveProposal $proposal): void
    {
        $settings = $this->settings->for($user);
        $disabled = is_array($settings->disabled_operational_rules) ? $settings->disabled_operational_rules : [];
        $event = $proposal->event?->event_type;
        $key = match ($event?->value) {
            'commitment.overdue' => 'overdue_commitment',
            'commitment.likely_done' => 'likely_done',
            'integration.blocked' => 'blocked_integration',
            default => null,
        };
        if ($key === null) {
            return;
        }
        $disabled[] = $key;
        $this->settings->update($user, ['disabled_operational_rules' => array_values(array_unique($disabled))]);
    }

    /**
     * @return array{ok: bool, status: string, error: ?string, reminder_id: ?int, sent: bool}
     */
    private function fail(ProactiveProposal $proposal, string $error): array
    {
        unset($proposal);

        return ['ok' => false, 'status' => ProactiveProposalStatus::Failed->value, 'error' => $error, 'reminder_id' => null, 'sent' => false];
    }
}
