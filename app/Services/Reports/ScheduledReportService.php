<?php

namespace App\Services\Reports;

use App\Enums\AutomationType;
use App\Enums\ScheduledReportPeriodMode;
use App\Enums\ScheduledReportScheduleKind;
use App\Enums\ScheduledReportStatus;
use App\Enums\ScheduledReportType;
use App\Models\AutomationRun;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Services\Automation\AutomationHealthService;
use App\Services\Users\UserCapability;
use App\Services\Workspace\Presentation\HumanAutomationResult;
use App\Services\Workspace\Presentation\HumanScheduledReportDescription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ScheduledReportService
{
    public function assertCanUse(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::SCHEDULED_REPORTS)) {
            throw new ScheduledReportException('capability_denied', 'Scheduled reports are not available.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input, string $createdBy = 'tool'): ScheduledReport
    {
        $this->assertCanUse($user);
        $normalized = $this->normalizeInput($user, $input);
        $now = CarbonImmutable::now('UTC');
        $next = ScheduledReportSchedule::firstRunAt($now, $normalized['timezone'], $normalized['local_time']);

        return ScheduledReport::query()->create([
            'user_id' => $user->id,
            'conversation_id' => $normalized['conversation_id'],
            'name' => $normalized['name'],
            'status' => ScheduledReportStatus::Active,
            'timezone' => $normalized['timezone'],
            'schedule_kind' => ScheduledReportScheduleKind::DailyLocal,
            'local_time' => $normalized['local_time'],
            'days' => null,
            'report_type' => $normalized['report_type'],
            'period_mode' => $normalized['period_mode'],
            'sources' => $normalized['sources'],
            'delivery' => $normalized['delivery'],
            'cursor' => ['baseline_established' => false],
            'next_run_at' => $next,
            'created_by' => $createdBy,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateOwned(User $user, int $reportId, array $attributes): ScheduledReport
    {
        $report = $this->requireOwned($user, $reportId);

        if ($report->status === ScheduledReportStatus::Cancelled) {
            throw new ScheduledReportException('not_editable', 'This report cannot be updated.');
        }

        $merged = [
            'name' => $attributes['name'] ?? $report->name,
            'report_type' => $attributes['report_type'] ?? $report->report_type->value,
            'period_mode' => $attributes['period_mode'] ?? $report->period_mode->value,
            'local_time' => $attributes['local_time'] ?? $report->local_time,
            'timezone' => $attributes['timezone'] ?? $report->timezone,
            'sources' => $attributes['sources'] ?? $report->sources,
            'delivery' => $attributes['delivery'] ?? $report->delivery,
            'conversation_id' => $report->conversation_id,
        ];

        if (isset($attributes['add_source']) && is_array($attributes['add_source'])) {
            $merged['sources'] = $this->mergeSource(is_array($merged['sources']) ? $merged['sources'] : [], $attributes['add_source']);
        }

        $normalized = $this->normalizeInput($user, $merged);
        $now = CarbonImmutable::now('UTC');
        $report->forceFill([
            'name' => $normalized['name'],
            'report_type' => $normalized['report_type'],
            'period_mode' => $normalized['period_mode'],
            'local_time' => $normalized['local_time'],
            'timezone' => $normalized['timezone'],
            'sources' => $normalized['sources'],
            'delivery' => $normalized['delivery'],
            'next_run_at' => ScheduledReportSchedule::firstRunAt($now, $normalized['timezone'], $normalized['local_time']),
        ])->save();

        return $report->fresh() ?? $report;
    }

    public function pauseOwned(User $user, int $reportId): ScheduledReport
    {
        $report = $this->requireOwned($user, $reportId);
        if ($report->status !== ScheduledReportStatus::Active) {
            throw new ScheduledReportException('not_pausable', 'This report cannot be paused.');
        }
        $report->forceFill(['status' => ScheduledReportStatus::Paused])->save();

        return $report->fresh() ?? $report;
    }

    public function resumeOwned(User $user, int $reportId): ScheduledReport
    {
        $report = $this->requireOwned($user, $reportId);
        if ($report->status !== ScheduledReportStatus::Paused) {
            throw new ScheduledReportException('not_resumable', 'This report cannot be resumed.');
        }
        $now = CarbonImmutable::now('UTC');
        $report->forceFill([
            'status' => ScheduledReportStatus::Active,
            'next_run_at' => ScheduledReportSchedule::firstRunAt($now, (string) $report->timezone, (string) $report->local_time),
        ])->save();

        return $report->fresh() ?? $report;
    }

    public function cancelOwned(User $user, int $reportId): ScheduledReport
    {
        $report = $this->requireOwned($user, $reportId);
        if ($report->status === ScheduledReportStatus::Cancelled) {
            throw new ScheduledReportException('not_cancellable', 'This report is already cancelled.');
        }
        $report->forceFill(['status' => ScheduledReportStatus::Cancelled, 'next_run_at' => null])->save();

        return $report->fresh() ?? $report;
    }

    public function requireOwned(User $user, int $reportId): ScheduledReport
    {
        $this->assertCanUse($user);
        $report = ScheduledReport::query()->where('user_id', $user->id)->whereKey($reportId)->first();

        if ($report === null) {
            throw new ScheduledReportException('not_found', 'Report was not found.');
        }

        return $report;
    }

    /**
     * @return Collection<int, ScheduledReport>
     */
    public function listFor(User $user, ?string $status = null): Collection
    {
        $this->assertCanUse($user);
        $builder = ScheduledReport::query()->where('user_id', $user->id)->orderBy('local_time')->orderBy('id');

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->limit(40)->get();
    }

    /**
     * @return list<ScheduledReport>
     */
    public function candidatesForMutation(User $user): array
    {
        $this->assertCanUse($user);

        return ScheduledReport::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ScheduledReportStatus::Active, ScheduledReportStatus::Paused])
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get()
            ->all();
    }

    public function activeCount(User $user): int
    {
        if (! $user->canUseCapability(UserCapability::SCHEDULED_REPORTS)) {
            return 0;
        }

        return ScheduledReport::query()
            ->where('user_id', $user->id)
            ->where('status', ScheduledReportStatus::Active)
            ->count();
    }

    /**
     * @param  list<ScheduledReportType>  $types
     */
    public function hasActiveOfTypes(User $user, array $types): bool
    {
        if ($types === [] || ! $user->canUseCapability(UserCapability::SCHEDULED_REPORTS)) {
            return false;
        }

        return ScheduledReport::query()
            ->where('user_id', $user->id)
            ->where('status', ScheduledReportStatus::Active)
            ->whereIn('report_type', array_map(static fn (ScheduledReportType $type): string => $type->value, $types))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function panelFor(User $user): array
    {
        $this->assertCanUse($user);
        $items = ScheduledReport::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [ScheduledReportStatus::Active, ScheduledReportStatus::Paused])
            ->orderBy('local_time')
            ->limit(40)
            ->get();
        $recent = ScheduledReport::query()
            ->where('user_id', $user->id)
            ->where('status', ScheduledReportStatus::Cancelled)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return [
            'items' => $items->map(fn (ScheduledReport $report): array => $this->serialize($report))->values()->all(),
            'recent' => $recent->map(fn (ScheduledReport $report): array => $this->serialize($report))->values()->all(),
            'active_count' => $this->activeCount($user),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(ScheduledReport $report): array
    {
        $sources = HumanScheduledReportDescription::sourceLabels($report);

        $health = (new AutomationHealthService)->forReport($report);
        $last = AutomationRun::latestFor(AutomationType::ScheduledReport, (int) $report->id);

        return [
            'id' => (int) $report->id,
            'name' => (string) $report->name,
            'description' => HumanScheduledReportDescription::sentence($report),
            'status' => $report->status->value,
            'status_label' => HumanScheduledReportDescription::statusLabel($report->status),
            'schedule_label' => HumanScheduledReportDescription::scheduleLabel($report),
            'period_label' => HumanScheduledReportDescription::periodLabel($report->period_mode),
            'source_labels' => $sources,
            'report_type' => $report->report_type->value,
            'period_mode' => $report->period_mode->value,
            'local_time' => (string) $report->local_time,
            'timezone' => (string) $report->timezone,
            'next_run_at' => optional($report->next_run_at)?->toIso8601String(),
            'last_success_at' => optional($report->last_success_at)?->toIso8601String(),
            'last_result_label' => HumanAutomationResult::label($last),
            'automation_health' => $health->value,
            'badge' => HumanAutomationResult::healthBadge($health),
            'pausable' => $report->status === ScheduledReportStatus::Active,
            'resumable' => $report->status === ScheduledReportStatus::Paused,
            'cancellable' => $report->status !== ScheduledReportStatus::Cancelled,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalizeInput(User $user, array $input): array
    {
        $type = ScheduledReportType::tryFrom((string) ($input['report_type'] ?? ''))
            ?? ScheduledReportType::CustomComposite;
        $period = ScheduledReportPeriodMode::tryFrom((string) ($input['period_mode'] ?? ''))
            ?? ScheduledReportIntent::inferPeriod('', $type);
        $time = ScheduledReportSchedule::normalizeTime($input['local_time'] ?? null)
            ?? '08:00';
        $timezone = ScheduledReportSchedule::timezoneFor($user, (string) ($input['timezone'] ?? 'UTC'));
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            $name = ScheduledReportIntent::defaultName($type);
        }

        $sources = is_array($input['sources'] ?? null) ? $input['sources'] : ScheduledReportIntent::defaultSources($type);
        $sources = $this->normalizeSources($sources);
        if ($sources === []) {
            throw new ScheduledReportException('invalid_sources', 'A scheduled report needs at least one source.');
        }

        $delivery = is_array($input['delivery'] ?? null) ? $input['delivery'] : [];

        return [
            'name' => mb_substr($name, 0, 180),
            'report_type' => $type,
            'period_mode' => $period,
            'local_time' => $time,
            'timezone' => $timezone,
            'sources' => $sources,
            'delivery' => [
                'telegram' => (bool) ($delivery['telegram'] ?? true),
                'web_notification' => (bool) ($delivery['web_notification'] ?? true),
                'skip_if_empty' => (bool) ($delivery['skip_if_empty'] ?? config('automation.skip_if_empty_default', false)),
            ],
            'conversation_id' => isset($input['conversation_id']) ? (int) $input['conversation_id'] : null,
        ];
    }

    /**
     * @param  list<mixed>  $sources
     * @return list<array<string, mixed>>
     */
    private function normalizeSources(array $sources): array
    {
        $allowed = ['tasks', 'reminders', 'projects', 'synthesis', 'google_calendar', 'gmail', 'telegram_groups', 'notifications', 'commitments'];
        $normalized = [];

        foreach ($sources as $source) {
            if (is_string($source)) {
                $source = ['type' => $source];
            }

            if (! is_array($source)) {
                continue;
            }

            $type = trim((string) ($source['type'] ?? ''));
            if (! in_array($type, $allowed, true)) {
                continue;
            }

            $row = ['type' => $type];
            foreach (['integration_account_id', 'project_id', 'telegram_group_id', 'scope'] as $key) {
                if (isset($source[$key]) && $source[$key] !== '' && $source[$key] !== null) {
                    $row[$key] = is_numeric($source[$key]) ? (int) $source[$key] : (string) $source[$key];
                }
            }
            if ($type === 'google_calendar') {
                $scope = (string) ($source['calendar_scope'] ?? 'all_relevant');
                $row['calendar_scope'] = in_array($scope, ['selected', 'all_relevant'], true)
                    ? $scope
                    : 'all_relevant';
                if (isset($source['calendar_ids']) && is_array($source['calendar_ids'])) {
                    $row['calendar_ids'] = array_values(array_filter(array_map('strval', $source['calendar_ids'])));
                }
                if (isset($source['calendar_names']) && is_array($source['calendar_names'])) {
                    $row['calendar_names'] = array_values(array_filter(array_map('strval', $source['calendar_names'])));
                }
            }
            if (in_array($type, ['gmail', 'telegram_groups'], true)) {
                $row['mode'] = (string) ($source['mode'] ?? 'new_since_last_report');
            }

            $normalized[] = $row;
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @param  array<string, mixed>  $added
     * @return list<array<string, mixed>>
     */
    private function mergeSource(array $sources, array $added): array
    {
        $type = (string) ($added['type'] ?? '');
        foreach ($sources as $index => $source) {
            if (($source['type'] ?? '') !== $type) {
                continue;
            }

            $sources[$index] = array_merge($source, $added);

            return array_values($sources);
        }

        $sources[] = $added;

        return array_values($sources);
    }
}
