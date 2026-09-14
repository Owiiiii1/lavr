<?php

namespace App\Services\Handover;

use App\Enums\CommitmentLifecycleStatus;
use App\Models\Commitment;
use App\Models\CommitmentEvidence;
use App\Models\CommitmentStatusHistory;
use App\Models\HandoverCleanupReport;
use App\Models\IntegrationAccount;
use App\Models\OperationalEvent;
use App\Models\ProactiveProposal;
use App\Models\ProactiveProposalAudit;
use App\Models\ProjectSourceBinding;
use App\Models\SourceItem;
use App\Models\User;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Validation\ValidationCleanupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class HandoverCleanupService
{
    public const CONFIRM_TOKEN = 'HANDOVER';

    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly ValidationCleanupService $batches,
    ) {}

    /**
     * @param  array{integration?: int|null, batch?: string|null, failed_jobs?: bool, logs?: bool}  $selectors
     * @return array<string, mixed>
     */
    public function plan(User $user, array $selectors): array
    {
        $this->assertSelector($selectors);

        $plan = [
            'operation_id' => (string) Str::uuid(),
            'dry_run' => true,
            'selectors' => $this->safeSelectors($selectors),
            'will_remove' => [],
            'will_preserve' => [],
            'counts' => [],
            'warnings' => [
                'Ensure a backup exists before --execute --confirm='.self::CONFIRM_TOKEN,
            ],
        ];

        if (! empty($selectors['integration'])) {
            $plan = $this->planIntegration($user, (int) $selectors['integration'], $plan);
        }

        if (! empty($selectors['batch'])) {
            $batchPlan = $this->batches->plan($user, (string) $selectors['batch']);
            $plan['will_remove']['validation_batch'] = $batchPlan['will_remove'];
            $plan['counts']['validation_batch'] = $batchPlan['counts'];
        }

        if (! empty($selectors['failed_jobs']) && Schema::hasTable('failed_jobs')) {
            $plan['counts']['failed_jobs'] = (int) DB::table('failed_jobs')->count();
            $plan['will_remove']['failed_jobs'] = $plan['counts']['failed_jobs'];
        }

        if (! empty($selectors['logs']) && Schema::hasTable('tool_execution_logs')) {
            $plan['counts']['tool_execution_logs'] = (int) DB::table('tool_execution_logs')->where('user_id', $user->id)->count();
            $plan['will_remove']['tool_execution_logs'] = $plan['counts']['tool_execution_logs'];
        }

        return $plan;
    }

    /**
     * @param  array{integration?: int|null, batch?: string|null, failed_jobs?: bool, logs?: bool}  $selectors
     * @return array<string, mixed>
     */
    public function run(User $user, array $selectors, bool $execute, ?string $confirm = null): array
    {
        $plan = $this->plan($user, $selectors);
        $plan['dry_run'] = ! $execute;

        if (! $execute) {
            $this->record($user, $plan, executed: false);

            return $plan;
        }

        if ($confirm !== self::CONFIRM_TOKEN) {
            throw new InvalidArgumentException('Destructive cleanup requires --confirm='.self::CONFIRM_TOKEN);
        }

        $plan['dry_run'] = false;
        $result = ['removed' => []];

        DB::transaction(function () use ($user, $selectors, &$result): void {
            if (! empty($selectors['integration'])) {
                $result['removed']['integration'] = $this->executeIntegration($user, (int) $selectors['integration']);
            }

            if (! empty($selectors['batch'])) {
                $result['removed']['validation_batch'] = $this->batches->execute($user, (string) $selectors['batch']);
            }

            if (! empty($selectors['failed_jobs']) && Schema::hasTable('failed_jobs')) {
                $result['removed']['failed_jobs'] = DB::table('failed_jobs')->delete();
            }

            if (! empty($selectors['logs']) && Schema::hasTable('tool_execution_logs')) {
                $result['removed']['tool_execution_logs'] = DB::table('tool_execution_logs')->where('user_id', $user->id)->delete();
            }
        });

        $plan['executed'] = true;
        $plan['result'] = $result;
        $this->record($user, $plan, executed: true);

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $selectors
     */
    private function assertSelector(array $selectors): void
    {
        $has = ! empty($selectors['integration'])
            || ! empty($selectors['batch'])
            || ! empty($selectors['failed_jobs'])
            || ! empty($selectors['logs']);

        if (! $has) {
            throw new InvalidArgumentException('handover-cleanup requires a selector (--integration, --batch, --failed-jobs, or --logs).');
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function planIntegration(User $user, int $accountId, array $plan): array
    {
        $account = $this->accounts->getAccount($user, $accountId);
        $itemIds = SourceItem::query()->where('user_id', $user->id)->where('integration_account_id', $account->id)->pluck('id');
        $eventIds = OperationalEvent::query()->where('user_id', $user->id)->where('source_id', $account->id)->pluck('id');
        $proposalIds = ProactiveProposal::query()->where('user_id', $user->id)->whereIn('operational_event_id', $eventIds)->pluck('id');
        $bindingCount = ProjectSourceBinding::query()
            ->where('source_id', $account->id)
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->count();

        $detected = $this->detectedOnlyFromAccount($user, $account);
        $preserved = Commitment::query()
            ->where('user_id', $user->id)
            ->whereIn('lifecycle_status', [CommitmentLifecycleStatus::Open, CommitmentLifecycleStatus::Confirmed, CommitmentLifecycleStatus::LikelyDone])
            ->get(['id', 'title', 'lifecycle_status']);

        $plan['integration'] = [
            'id' => $account->id,
            'label' => $account->label(),
            'provider' => $account->provider,
        ];
        $plan['counts']['source_items'] = $itemIds->count();
        $plan['counts']['project_source_bindings'] = $bindingCount;
        $plan['counts']['operational_events'] = $eventIds->count();
        $plan['counts']['proactive_proposals'] = $proposalIds->count();
        $plan['counts']['detected_commitments'] = $detected->count();
        $plan['will_remove'] = [
            'integration_account' => $account->label(),
            'oauth_tokens' => true,
            'source_items' => $itemIds->count(),
            'project_source_bindings' => $bindingCount,
            'operational_events' => $eventIds->count(),
            'proactive_proposals' => $proposalIds->count(),
            'unconfirmed_detected_commitments' => $detected->pluck('title')->all(),
        ];
        $plan['will_preserve'] = [
            'people' => 'canonical people',
            'projects' => 'canonical projects',
            'confirmed_commitments' => $preserved->pluck('title')->all(),
        ];
        $plan['remote_revoke'] = $account->provider === 'google' ? 'attempted' : 'local_credentials_only';

        return $plan;
    }

    /**
     * @return array<string, int>
     */
    private function executeIntegration(User $user, int $accountId): array
    {
        $account = $this->accounts->getAccount($user, $accountId);
        $eventIds = OperationalEvent::query()->where('user_id', $user->id)->where('source_id', $account->id)->pluck('id');
        $proposalIds = ProactiveProposal::query()->where('user_id', $user->id)->whereIn('operational_event_id', $eventIds)->pluck('id');

        ProactiveProposalAudit::query()->whereIn('proactive_proposal_id', $proposalIds)->delete();
        ProactiveProposal::query()->whereIn('id', $proposalIds)->delete();
        OperationalEvent::query()->whereIn('id', $eventIds)->delete();

        $detected = $this->detectedOnlyFromAccount($user, $account);
        $detectedIds = $detected->pluck('id');
        CommitmentEvidence::query()->whereIn('commitment_id', $detectedIds)->delete();
        CommitmentStatusHistory::query()->whereIn('commitment_id', $detectedIds)->delete();
        Commitment::query()->whereIn('id', $detectedIds)->delete();

        CommitmentEvidence::query()
            ->where('source_id', $account->id)
            ->whereHas('commitment', fn ($query) => $query->where('user_id', $user->id))
            ->delete();

        $removedItems = SourceItem::query()
            ->where('user_id', $user->id)
            ->where('integration_account_id', $account->id)
            ->delete();

        $bindings = ProjectSourceBinding::query()
            ->where('source_id', $account->id)
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->delete();

        $this->accounts->disconnect($account, false);

        return [
            'source_items' => $removedItems,
            'project_source_bindings' => $bindings,
            'operational_events' => $eventIds->count(),
            'proactive_proposals' => $proposalIds->count(),
            'detected_commitments' => $detectedIds->count(),
        ];
    }

    /**
     * @return Collection<int, Commitment>
     */
    private function detectedOnlyFromAccount(User $user, IntegrationAccount $account)
    {
        return Commitment::query()
            ->where('user_id', $user->id)
            ->where('lifecycle_status', CommitmentLifecycleStatus::Detected)
            ->get()
            ->filter(function (Commitment $commitment) use ($account): bool {
                $evidence = CommitmentEvidence::query()->where('commitment_id', $commitment->id)->get();
                if ($evidence->isEmpty()) {
                    return (int) $commitment->source_id === (int) $account->id;
                }

                return $evidence->every(fn (CommitmentEvidence $row): bool => (int) $row->source_id === (int) $account->id);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function record(User $user, array $plan, bool $executed): void
    {
        $safe = $plan;
        unset($safe['credentials'], $safe['token'], $safe['secret']);

        HandoverCleanupReport::query()->create([
            'user_id' => $user->id,
            'operation_id' => $plan['operation_id'],
            'selectors_json' => $plan['selectors'],
            'dry_run' => (bool) ($plan['dry_run'] ?? true),
            'executed' => $executed,
            'plan_json' => $safe,
            'result_json' => $plan['result'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $selectors
     * @return array<string, mixed>
     */
    private function safeSelectors(array $selectors): array
    {
        return [
            'integration' => $selectors['integration'] ?? null,
            'batch' => $selectors['batch'] ?? null,
            'failed_jobs' => (bool) ($selectors['failed_jobs'] ?? false),
            'logs' => (bool) ($selectors['logs'] ?? false),
        ];
    }
}
