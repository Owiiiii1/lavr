<?php

namespace App\Services\Watchers;

use App\Enums\AutomationType;
use App\Enums\WatcherConditionType;
use App\Enums\WatcherCreatedBy;
use App\Enums\WatcherHealth;
use App\Enums\WatcherMode;
use App\Enums\WatcherReactionType;
use App\Enums\WatcherSourceType;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use App\Jobs\EvaluateWatcherJob;
use App\Models\AutomationRun;
use App\Models\IntegrationAccount;
use App\Models\KnowledgeEntity;
use App\Models\Project;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Watcher;
use App\Models\WatcherOccurrence;
use App\Services\Automation\AutomationHealthService;
use App\Services\Knowledge\KnowledgeEntityResolver;
use App\Services\Knowledge\KnowledgeNameNormalizer;
use App\Services\Synthesis\SynthesisCache;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Workspace\Presentation\HumanAutomationResult;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use App\Services\Workspace\Presentation\HumanWatcherDescription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

final class WatcherService
{
    public function __construct(
        private readonly KnowledgeEntityResolver $entities = new KnowledgeEntityResolver,
    ) {}

    public function activeCount(User $user): int
    {
        if (! $user->canUseCapability(UserCapability::WATCHERS)) {
            return 0;
        }

        return Watcher::query()
            ->where('user_id', $user->id)
            ->where('status', WatcherStatus::Active)
            ->count();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(User $user, array $input, WatcherCreatedBy $createdBy = WatcherCreatedBy::Tool): Watcher
    {
        $this->assertCanUse($user);
        $name = WatcherSupport::displayName((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new WatcherException('invalid_name', 'Watcher name is required.');
        }

        $trigger = WatcherTriggerType::tryFromLoose($input['trigger_type'] ?? null);
        $sourceType = WatcherSourceType::tryFromLoose($input['source_type'] ?? null) ?? $this->defaultSource($trigger);
        $condition = WatcherConditionType::tryFromLoose($input['condition_type'] ?? null);
        $reaction = WatcherReactionType::tryFromLoose($input['reaction_type'] ?? $input['reaction'] ?? 'notify') ?? WatcherReactionType::Notify;
        if ($trigger === null || $sourceType === null || $condition === null) {
            throw new WatcherException('invalid_config', 'Watcher trigger, source, and condition must be explicit.');
        }

        $explicitMode = ($input['mode'] ?? $input['recurring'] ?? null) === true || ($input['mode'] ?? '') === 'recurring'
            ? WatcherMode::Recurring
            : (($input['one_shot'] ?? false) === true || ($input['mode'] ?? '') === 'one_shot'
                ? WatcherMode::OneShot
                : null);

        $this->assertSourceCapability($user, $trigger);
        $this->assertLimits($user, $trigger);

        $sourceConfig = $this->boundConfig(is_array($input['source'] ?? $input['source_config'] ?? null) ? ($input['source'] ?? $input['source_config']) : []);
        $conditionConfig = $this->boundConfig(is_array($input['condition'] ?? $input['condition_config'] ?? null) ? ($input['condition'] ?? $input['condition_config']) : []);
        $reactionConfig = $this->boundConfig(is_array($input['reaction_config'] ?? null) ? $input['reaction_config'] : []);
        $sourceConfig = $this->normalizeGmailSource($trigger, $sourceConfig);
        $this->copySourceFilters($sourceConfig, $conditionConfig);

        if (WatcherSchedule::isDigest($sourceConfig)) {
            if (! isset($sourceConfig['query']) || trim((string) $sourceConfig['query']) === '') {
                $sourceConfig['query'] = 'in:inbox';
            }
            $sourceConfig['digest'] = true;
            $schedule = is_array($sourceConfig['schedule'] ?? null) ? $sourceConfig['schedule'] : [];
            $schedule['kind'] = WatcherSchedule::KIND_DAILY_LOCAL;
            $schedule['local_time'] = WatcherSchedule::localTime($sourceConfig);
            $schedule['timezone'] = WatcherSchedule::timezoneFor($user, (string) ($schedule['timezone'] ?? 'UTC'));
            $sourceConfig['schedule'] = $schedule;
        }

        $this->assertBoundedSource($trigger, $sourceConfig, $conditionConfig);

        $isGmailEvent = $trigger === WatcherTriggerType::GmailMessage && ! WatcherSchedule::isDigest($sourceConfig);

        $mode = $explicitMode ?? (WatcherSchedule::isDigest($sourceConfig) || $isGmailEvent
            ? WatcherMode::Recurring
            : $this->defaultMode($trigger, $condition));

        if ($condition === WatcherConditionType::StatusEquals && ! isset($conditionConfig['status']) && ! isset($conditionConfig['expected'])) {
            $conditionConfig['status'] = 'open';
        }

        if (isset($input['hours']) && is_numeric($input['hours']) && ! isset($conditionConfig['hours'])) {
            $conditionConfig['hours'] = max(1, (int) $input['hours']);
        }

        $ids = $this->resolveRefs($user, $input, $sourceConfig);

        if (in_array($trigger, [WatcherTriggerType::TaskState, WatcherTriggerType::TimeCondition], true) && ($ids['task_id'] ?? null) === null) {
            throw new WatcherException('invalid_config', 'Task and time watchers need an owned task_id.');
        }
        if ($trigger === WatcherTriggerType::ReminderState && ($ids['reminder_id'] ?? null) === null) {
            throw new WatcherException('invalid_config', 'Reminder watchers need an owned reminder_id.');
        }

        $watcher = Watcher::query()->create([
            'user_id' => $user->id,
            'public_id' => (string) Str::uuid(),
            'name' => $name,
            'status' => WatcherStatus::Active,
            'health' => WatcherHealth::Waiting,
            'mode' => $mode,
            'trigger_type' => $trigger,
            'source_type' => $sourceType,
            'source_config' => $sourceConfig,
            'condition_type' => $condition,
            'condition_config' => $conditionConfig,
            'reaction_type' => $reaction,
            'reaction_config' => $reactionConfig,
            'cooldown_seconds' => max(0, (int) ($input['cooldown_seconds'] ?? (($isGmailEvent || WatcherSchedule::isDigest($sourceConfig))
                ? 0
                : config('watchers.defaults.cooldown_seconds', 3600)))),
            'max_triggers_per_day' => max(1, (int) ($input['max_triggers_per_day'] ?? (WatcherSchedule::isDigest($sourceConfig)
                ? 3
                : ($isGmailEvent ? 24 : config('watchers.limits.max_triggers_per_day', 8))))),
            'aggregation_window_seconds' => max(0, (int) ($input['aggregation_window_seconds'] ?? ($isGmailEvent
                ? 0
                : config('watchers.defaults.aggregation_window_seconds', 300)))),
            'next_check_at' => CarbonImmutable::now('UTC'),
            'cursor' => ['baseline_established' => false],
            'created_by' => $createdBy,
            'conversation_id' => isset($input['conversation_id']) ? (int) $input['conversation_id'] : null,
            'project_id' => $ids['project_id'],
            'knowledge_entity_id' => $ids['knowledge_entity_id'],
            'task_id' => $ids['task_id'],
            'reminder_id' => $ids['reminder_id'],
            'integration_account_id' => $ids['integration_account_id'],
        ]);

        EvaluateWatcherJob::dispatch((int) $watcher->id);
        $this->bumpSynthesis((int) $user->id);

        return $watcher;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function updateOwned(User $user, int $id, array $input): Watcher
    {
        $watcher = $this->requireOwned($user, $id);
        $resetCursor = false;
        $updates = [];

        if (isset($input['name'])) {
            $name = WatcherSupport::displayName((string) $input['name']);
            if ($name === '') {
                throw new WatcherException('invalid_name', 'Watcher name is required.');
            }
            $updates['name'] = $name;
        }

        foreach (['source', 'source_config', 'condition', 'condition_config', 'trigger_type'] as $key) {
            if (array_key_exists($key, $input)) {
                $resetCursor = true;
            }
        }

        if (isset($input['trigger_type'])) {
            $trigger = WatcherTriggerType::tryFromLoose($input['trigger_type']);
            if ($trigger === null) {
                throw new WatcherException('invalid_config', 'Trigger type is invalid.');
            }
            $this->assertSourceCapability($user, $trigger);
            $updates['trigger_type'] = $trigger;
        }

        if (isset($input['condition_type'])) {
            $condition = WatcherConditionType::tryFromLoose($input['condition_type']);
            if ($condition === null) {
                throw new WatcherException('invalid_config', 'Condition type is invalid.');
            }
            $updates['condition_type'] = $condition;
        }

        if (isset($input['reaction_type'])) {
            $reaction = WatcherReactionType::tryFromLoose($input['reaction_type']);
            if ($reaction === null) {
                throw new WatcherException('invalid_config', 'Reaction type is invalid.');
            }
            $updates['reaction_type'] = $reaction;
        }

        if (isset($input['source']) || isset($input['source_config'])) {
            $incoming = $this->boundConfig((array) ($input['source'] ?? $input['source_config']));
            $current = is_array($watcher->source_config) ? $watcher->source_config : [];
            $trigger = isset($updates['trigger_type']) ? $updates['trigger_type'] : $watcher->trigger_type;
            $updates['source_config'] = $this->normalizeGmailSource($trigger, $incoming, $current);
        }
        if (isset($input['condition']) || isset($input['condition_config'])) {
            $updates['condition_config'] = $this->boundConfig((array) ($input['condition'] ?? $input['condition_config']));
        }
        if (isset($input['reaction_config'])) {
            $updates['reaction_config'] = $this->boundConfig((array) $input['reaction_config']);
        }
        if (isset($input['cooldown_seconds'])) {
            $updates['cooldown_seconds'] = max(0, (int) $input['cooldown_seconds']);
        }

        if ($resetCursor) {
            $updates['cursor'] = ['baseline_established' => false];
            $updates['health'] = WatcherHealth::Waiting;
            $updates['next_check_at'] = CarbonImmutable::now('UTC');
        }

        if ($updates !== []) {
            $watcher->forceFill($updates)->save();
        }

        if ($resetCursor) {
            EvaluateWatcherJob::dispatch((int) $watcher->id);
        }

        if ($updates !== []) {
            $this->bumpSynthesis((int) $user->id);
        }

        return $watcher->fresh() ?? $watcher;
    }

    public function pauseOwned(User $user, int $id): Watcher
    {
        $watcher = $this->requireOwned($user, $id);
        $watcher->forceFill([
            'status' => WatcherStatus::Paused,
            'health' => WatcherHealth::Paused,
        ])->save();
        $this->bumpSynthesis((int) $user->id);

        return $watcher;
    }

    public function resumeOwned(User $user, int $id): Watcher
    {
        $watcher = $this->requireOwned($user, $id);
        $watcher->forceFill([
            'status' => WatcherStatus::Active,
            'health' => WatcherHealth::Waiting,
            'next_check_at' => CarbonImmutable::now('UTC'),
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_error_category' => null,
        ])->save();
        EvaluateWatcherJob::dispatch((int) $watcher->id);
        $this->bumpSynthesis((int) $user->id);

        return $watcher;
    }

    public function cancelOwned(User $user, int $id): Watcher
    {
        $watcher = $this->requireOwned($user, $id);
        $watcher->forceFill([
            'status' => WatcherStatus::Cancelled,
            'health' => WatcherHealth::Paused,
        ])->save();
        $this->bumpSynthesis((int) $user->id);

        return $watcher;
    }

    public function requireOwned(User $user, int $id): Watcher
    {
        $this->assertCanUse($user);
        $watcher = Watcher::query()->where('user_id', $user->id)->whereKey($id)->first();
        if ($watcher === null) {
            throw new WatcherException('not_found', 'Watcher was not found.');
        }

        return $watcher;
    }

    public function requireOwnedByPublicId(User $user, string $publicId): Watcher
    {
        $this->assertCanUse($user);
        $watcher = Watcher::query()->where('user_id', $user->id)->where('public_id', $publicId)->first();
        if ($watcher === null) {
            throw new WatcherException('not_found', 'Watcher was not found.');
        }

        return $watcher;
    }

    /**
     * @return Collection<int, Watcher>
     */
    public function listFor(User $user, ?string $status = null): Collection
    {
        $this->assertCanUse($user);
        $query = Watcher::query()
            ->where('user_id', $user->id)
            ->with(['task:id,user_id,title', 'project:id,user_id,name', 'knowledgeEntity:id,user_id,name', 'reminder:id,user_id,text'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        $parsed = $status !== null && $status !== '' ? WatcherStatus::tryFrom($status) : null;
        if ($parsed !== null) {
            $query->where('status', $parsed);
        }

        return $query->limit(80)->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function panelFor(User $user): array
    {
        $this->assertCanUse($user);
        $watchers = $this->listFor($user);
        $recent = WatcherOccurrence::query()
            ->where('user_id', $user->id)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $timezone = (string) ($user->timezone ?: 'UTC');

        return [
            'active_count' => $watchers->where('status', WatcherStatus::Active)->count(),
            'items' => $watchers->map(fn (Watcher $watcher): array => $this->serialize($watcher, $timezone))->values()->all(),
            'recent' => $recent->map(fn (WatcherOccurrence $row): array => $this->serializeOccurrence($row, $timezone))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(Watcher $watcher, ?string $timezone = null): array
    {
        $timezone = (string) ($timezone ?: 'UTC');
        $names = $this->linkedNames($watcher);

        $health = (new AutomationHealthService)->forWatcher($watcher);
        $last = AutomationRun::latestFor(AutomationType::Watcher, (int) $watcher->id);

        return [
            'id' => (int) $watcher->id,
            'public_id' => $watcher->public_id,
            'name' => $watcher->name,
            'description' => HumanWatcherDescription::sentence($watcher, $names, $timezone),
            'state_label' => HumanStatusLabel::watcherState($watcher, $timezone),
            'problem_label' => HumanStatusLabel::watcherProblem($watcher),
            'last_triggered_label' => HumanMoment::label($watcher->last_triggered_at, $timezone),
            'last_result_label' => HumanAutomationResult::label($last),
            'automation_health' => $health->value,
            'badge' => HumanAutomationResult::healthBadge($health),
            'linked' => array_filter([
                'task' => $names['task'],
                'project' => $names['project'],
                'entity' => $names['entity'],
                'reminder' => $names['reminder'],
            ], static fn (?string $value): bool => $value !== null && $value !== ''),
            'pausable' => $watcher->status === WatcherStatus::Active,
            'resumable' => $watcher->status === WatcherStatus::Paused,
            'cancellable' => in_array($watcher->status, [WatcherStatus::Active, WatcherStatus::Paused], true),
            'status' => $watcher->status->value,
            'health' => $watcher->health->value,
            'mode' => $watcher->mode->value,
            'trigger_type' => $watcher->trigger_type->value,
            'source_type' => $watcher->source_type->value,
            'condition_type' => $watcher->condition_type->value,
            'reaction_type' => $watcher->reaction_type->value,
            'cooldown_seconds' => (int) $watcher->cooldown_seconds,
            'last_checked_at' => optional($watcher->last_checked_at)?->toIso8601String(),
            'last_triggered_at' => optional($watcher->last_triggered_at)?->toIso8601String(),
            'next_check_at' => optional($watcher->next_check_at)?->toIso8601String(),
            'project_id' => $watcher->project_id,
            'task_id' => $watcher->task_id,
            'knowledge_entity_id' => $watcher->knowledge_entity_id,
            'reminder_id' => $watcher->reminder_id,
            'source_config' => is_array($watcher->source_config) ? $watcher->source_config : [],
            'condition_config' => is_array($watcher->condition_config) ? $watcher->condition_config : [],
            'reaction_config' => is_array($watcher->reaction_config) ? $watcher->reaction_config : [],
        ];
    }

    /**
     * @return array{task: ?string, entity: ?string, project: ?string, reminder: ?string}
     */
    private function linkedNames(Watcher $watcher): array
    {
        $owned = static function (mixed $model, string $attribute) use ($watcher): ?string {
            if ($model === null || (int) $model->user_id !== (int) $watcher->user_id) {
                return null;
            }

            $value = trim((string) $model->{$attribute});

            return $value !== '' ? $value : null;
        };

        return [
            'task' => $owned($watcher->task, 'title'),
            'entity' => $owned($watcher->knowledgeEntity, 'name'),
            'project' => $owned($watcher->project, 'name'),
            'reminder' => $owned($watcher->reminder, 'text'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOccurrence(WatcherOccurrence $occurrence, ?string $timezone = null): array
    {
        $meta = is_array($occurrence->metadata) ? $occurrence->metadata : [];

        return [
            'id' => (int) $occurrence->id,
            'watcher_id' => (int) $occurrence->watcher_id,
            'detected_at' => optional($occurrence->detected_at)?->toIso8601String(),
            'detected_label' => HumanMoment::label($occurrence->detected_at, (string) ($timezone ?: 'UTC')),
            'status' => $occurrence->status->value,
            'matched_condition' => $occurrence->matched_condition,
            'reaction_status' => $occurrence->reaction_status->value,
            'summary' => (string) ($meta['summary'] ?? ($meta['title'] ?? '')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function occurrencesFor(User $user, int $watcherId, int $limit = 20): array
    {
        $watcher = $this->requireOwned($user, $watcherId);

        return WatcherOccurrence::query()
            ->where('user_id', $user->id)
            ->where('watcher_id', $watcher->id)
            ->orderByDesc('detected_at')
            ->orderByDesc('id')
            ->limit(max(1, min(50, $limit)))
            ->get()
            ->map(fn (WatcherOccurrence $row): array => $this->serializeOccurrence($row))
            ->values()
            ->all();
    }

    private function bumpSynthesis(int $userId): void
    {
        try {
            app(SynthesisCache::class)->bumpUserId($userId);
        } catch (Throwable) {
        }
    }

    private function assertCanUse(User $user): void
    {
        if (! $user->isActive() || ! $user->canUseCapability(UserCapability::WATCHERS)) {
            throw new WatcherException('capability_denied', 'Watchers are not available.');
        }
    }

    private function assertSourceCapability(User $user, WatcherTriggerType $trigger): void
    {
        $needed = match ($trigger) {
            WatcherTriggerType::GmailMessage => UserCapability::GMAIL,
            WatcherTriggerType::CalendarEvent => UserCapability::GOOGLE_CALENDAR,
            WatcherTriggerType::GithubEvent => UserCapability::GITHUB,
            default => null,
        };

        if ($needed !== null && ! $user->canUseCapability($needed)) {
            throw new WatcherException('capability_denied', 'This watcher source is not available.');
        }
    }

    private function assertLimits(User $user, WatcherTriggerType $trigger): void
    {
        $active = Watcher::query()->where('user_id', $user->id)->where('status', WatcherStatus::Active);
        $max = $trigger->isExternal()
            ? max(1, (int) config('watchers.limits.max_active_external', 25))
            : max(1, (int) config('watchers.limits.max_active_internal', 100));

        $count = (clone $active)
            ->when($trigger->isExternal(), fn ($query) => $query->whereIn('trigger_type', [
                WatcherTriggerType::GmailMessage->value,
                WatcherTriggerType::CalendarEvent->value,
                WatcherTriggerType::GithubEvent->value,
            ]), fn ($query) => $query->whereNotIn('trigger_type', [
                WatcherTriggerType::GmailMessage->value,
                WatcherTriggerType::CalendarEvent->value,
                WatcherTriggerType::GithubEvent->value,
            ]))
            ->count();

        if ($count >= $max) {
            throw new WatcherException('limit_reached', 'Active watcher limit reached.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $sourceConfig
     * @return array{project_id: ?int, knowledge_entity_id: ?int, task_id: ?int, reminder_id: ?int, integration_account_id: ?int}
     */
    private function resolveRefs(User $user, array $input, array &$sourceConfig): array
    {
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : (isset($sourceConfig['project_id']) ? (int) $sourceConfig['project_id'] : null);
        $entityId = isset($input['knowledge_entity_id']) ? (int) $input['knowledge_entity_id'] : (isset($sourceConfig['knowledge_entity_id']) ? (int) $sourceConfig['knowledge_entity_id'] : null);
        $taskId = isset($input['task_id']) ? (int) $input['task_id'] : (isset($sourceConfig['task_id']) ? (int) $sourceConfig['task_id'] : null);
        $reminderId = isset($input['reminder_id']) ? (int) $input['reminder_id'] : (isset($sourceConfig['reminder_id']) ? (int) $sourceConfig['reminder_id'] : null);

        if ($projectId !== null && $projectId > 0) {
            $owned = Project::query()->where('user_id', $user->id)->whereKey($projectId)->exists();
            if (! $owned) {
                throw new WatcherException('not_found', 'Project was not found.');
            }
        } else {
            $projectId = null;
        }

        if ($entityId !== null && $entityId > 0) {
            $owned = KnowledgeEntity::query()->where('user_id', $user->id)->whereKey($entityId)->exists();
            if (! $owned) {
                throw new WatcherException('not_found', 'Knowledge entity was not found.');
            }
        } elseif (isset($input['entity_name']) || isset($sourceConfig['entity_name'])) {
            $name = (string) ($input['entity_name'] ?? $sourceConfig['entity_name']);
            $matches = $this->entities->search($user, $name, null, 5);
            if ($matches->count() > 1) {
                throw new WatcherException('ambiguous', 'Several knowledge entities match that name.', $matches->map(static fn (KnowledgeEntity $entity): array => [
                    'id' => (int) $entity->id,
                    'name' => $entity->name,
                    'type' => $entity->type->value,
                ])->values()->all());
            }
            $match = $matches->first() ?? $this->entities->findByNormalizedName($user, KnowledgeNameNormalizer::name($name));
            if ($match === null) {
                throw new WatcherException('not_found', 'Knowledge entity was not found.');
            }
            $entityId = (int) $match->id;
            if ($match->project_id) {
                $projectId = $projectId ?? (int) $match->project_id;
            }
        } else {
            $entityId = null;
        }

        if ($taskId !== null && $taskId > 0) {
            $owned = Task::query()->where('user_id', $user->id)->whereKey($taskId)->exists();
            if (! $owned) {
                throw new WatcherException('not_found', 'Task was not found.');
            }
        } else {
            $taskId = null;
        }

        if ($reminderId !== null && $reminderId > 0) {
            $owned = Reminder::query()->where('user_id', $user->id)->whereKey($reminderId)->exists();
            if (! $owned) {
                throw new WatcherException('not_found', 'Reminder was not found.');
            }
        } else {
            $reminderId = null;
        }

        $accountId = isset($input['integration_account_id'])
            ? (int) $input['integration_account_id']
            : (isset($sourceConfig['integration_account_id']) ? (int) $sourceConfig['integration_account_id'] : null);

        if ($accountId !== null && $accountId > 0) {
            $owned = IntegrationAccount::query()->where('user_id', $user->id)->whereKey($accountId)->exists();
            if (! $owned) {
                throw new WatcherException('not_found', 'Integration account was not found.');
            }
        } else {
            $accountId = null;
        }

        return [
            'project_id' => $projectId,
            'knowledge_entity_id' => $entityId,
            'task_id' => $taskId,
            'reminder_id' => $reminderId,
            'integration_account_id' => $accountId,
        ];
    }

    private function defaultSource(?WatcherTriggerType $trigger): ?WatcherSourceType
    {
        return match ($trigger) {
            WatcherTriggerType::KnowledgeEvent => WatcherSourceType::KnowledgeEntity,
            WatcherTriggerType::TaskState, WatcherTriggerType::TimeCondition => WatcherSourceType::Task,
            WatcherTriggerType::ReminderState => WatcherSourceType::Reminder,
            WatcherTriggerType::GmailMessage => WatcherSourceType::Gmail,
            WatcherTriggerType::CalendarEvent => WatcherSourceType::Calendar,
            WatcherTriggerType::GithubEvent => WatcherSourceType::Github,
            default => null,
        };
    }

    private function defaultMode(WatcherTriggerType $trigger, WatcherConditionType $condition): WatcherMode
    {
        if (in_array($condition, [WatcherConditionType::ThreadReceivedReply, WatcherConditionType::DeadlineWithin, WatcherConditionType::OverdueBy], true)) {
            return WatcherMode::OneShot;
        }

        return in_array($trigger, [
            WatcherTriggerType::GithubEvent,
            WatcherTriggerType::KnowledgeEvent,
            WatcherTriggerType::GmailMessage,
        ], true)
            ? WatcherMode::Recurring
            : WatcherMode::OneShot;
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>
     */
    private function normalizeGmailSource(WatcherTriggerType $trigger, array $sourceConfig, ?array $existing = null): array
    {
        if ($trigger !== WatcherTriggerType::GmailMessage) {
            return $sourceConfig;
        }

        if (WatcherSchedule::isDigest($sourceConfig)) {
            return $sourceConfig;
        }

        if ($existing !== null && ! WatcherSchedule::isDigest($existing)) {
            return GmailWatcherQuery::merge($existing, $sourceConfig);
        }

        unset($sourceConfig['digest'], $sourceConfig['schedule']);

        return GmailWatcherQuery::normalize($sourceConfig);
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     * @param  array<string, mixed>  $conditionConfig
     */
    private function copySourceFilters(array $sourceConfig, array &$conditionConfig): void
    {
        foreach (['thread_id', 'sender', 'subject', 'event_type', 'status', 'repository', 'branch'] as $key) {
            if ($key === 'sender' && (isset($sourceConfig['sender_domains']) || (isset($sourceConfig['senders']) && is_array($sourceConfig['senders']) && count($sourceConfig['senders']) > 1))) {
                continue;
            }
            if (! isset($conditionConfig[$key]) && isset($sourceConfig[$key]) && $sourceConfig[$key] !== '') {
                $conditionConfig[$key] = $sourceConfig[$key];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $sourceConfig
     * @param  array<string, mixed>  $conditionConfig
     */
    private function assertBoundedSource(WatcherTriggerType $trigger, array $sourceConfig, array $conditionConfig): void
    {
        $has = static fn (string $key): bool => isset($sourceConfig[$key]) && trim((string) $sourceConfig[$key]) !== '';

        match ($trigger) {
            WatcherTriggerType::GmailMessage => (GmailWatcherQuery::hasFilter($sourceConfig) || isset($conditionConfig['sender']) || isset($conditionConfig['thread_id']) || WatcherSchedule::isDigest($sourceConfig))
                ? null
                : throw new WatcherException('invalid_config', 'Gmail watchers need a sender, domain, subject, thread, or query.'),
            WatcherTriggerType::GithubEvent => $has('repository')
                ? null
                : throw new WatcherException('invalid_config', 'GitHub watchers need a repository.'),
            WatcherTriggerType::CalendarEvent => ($has('event_id') || $has('query') || $has('calendar_id'))
                ? null
                : throw new WatcherException('invalid_config', 'Calendar watchers need an event id, calendar, or query.'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function boundConfig(array $config): array
    {
        unset($config['token'], $config['access_token'], $config['body'], $config['raw'], $config['user_id'], $config['integration_account_id']);

        return WatcherSupport::boundMetadata($config);
    }
}
