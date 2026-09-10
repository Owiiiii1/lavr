<?php

namespace App\Services\Reminders;

use App\Enums\AutomationType;
use App\Enums\ReminderStatus;
use App\Models\AutomationRun;
use App\Models\ChannelIdentity;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Reminder;
use App\Models\ReminderOccurrence;
use App\Models\Task;
use App\Models\User;
use App\Services\Automation\AutomationHealthService;
use App\Services\Knowledge\KnowledgeDeterministicIngestor;
use App\Services\Users\UserCapability;
use App\Services\Watchers\WatcherEvaluationDispatcher;
use App\Services\Workspace\Presentation\HumanAutomationResult;
use App\Services\Workspace\Presentation\HumanMoment;
use App\Services\Workspace\Presentation\HumanStatusLabel;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ReminderService
{
    public function __construct(
        private readonly KnowledgeDeterministicIngestor $knowledge = new KnowledgeDeterministicIngestor,
        private readonly ReminderRecurrenceCalculator $recurrence = new ReminderRecurrenceCalculator,
        private readonly PushSubscriptionService $pushSubscriptions = new PushSubscriptionService,
    ) {}

    public function create(
        User $user,
        string $text,
        CarbonImmutable $runAt,
        string $timezone,
        ?Conversation $conversation = null,
        ?Message $sourceMessage = null,
        ?string $recurrence = null,
        ?int $taskId = null,
    ): Reminder {
        $rule = $this->normalizeRecurrence($recurrence);
        $this->validateCreate($user, $text, $runAt, $timezone);

        $utc = $runAt->utc();
        $originalLocal = $runAt->setTimezone($timezone)->format('Y-m-d\TH:i:sP');

        $reminder = Reminder::query()->create([
            'user_id' => $user->id,
            'source_conversation_id' => $conversation?->id,
            'source_message_id' => $sourceMessage?->id,
            'task_id' => $this->ownedTaskId($user, $taskId),
            'text' => $text,
            'run_at' => $utc,
            'timezone' => $timezone,
            'original_local_time' => $originalLocal,
            'status' => ReminderStatus::Scheduled,
            'recurrence_rule' => $rule,
            'metadata' => [
                'attempts' => 0,
            ],
        ]);

        try {
            Log::info('reminder created', [
                'reminder_id' => $reminder->id,
                'user_id' => $user->id,
                'status' => ReminderStatus::Scheduled->value,
            ]);
        } catch (Throwable) {
        }

        $this->knowledge->reminderCreated($reminder);
        $this->notifyWatchers($reminder);

        return $reminder;
    }

    public function validateCreate(User $user, string $text, CarbonImmutable $runAt, string $timezone): void
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }

        if (! $user->isActive()) {
            throw new ReminderException('user_inactive', 'User is not active.');
        }

        if (trim($text) === '') {
            throw new ReminderException('empty_text', 'Reminder text is empty.');
        }

        if (! $this->isValidTimezone($timezone)) {
            throw new ReminderException('invalid_timezone', 'Timezone is invalid.');
        }

        if ($runAt->utc()->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new ReminderException('past_time', 'Reminder time is in the past.');
        }
    }

    public function normalizeRecurrence(?string $rule): ?string
    {
        if ($rule === null || trim($rule) === '') {
            return null;
        }

        $normalized = ReminderRecurrenceCalculator::normalize($rule);

        if ($normalized === null) {
            throw new ReminderException('invalid_recurrence', 'Recurrence is not supported.');
        }

        return $normalized;
    }

    public function telegramIsLinked(User $user): bool
    {
        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);

        return $identity !== null && filled($identity->external_chat_id);
    }

    public function webPushIsAvailable(User $user): bool
    {
        return VapidConfig::isConfigured() && $this->pushSubscriptions->hasActive($user);
    }

    public function localWallTimeToUtc(string $runAtLocal, string $timezone): CarbonImmutable
    {
        if (! $this->isValidTimezone($timezone)) {
            throw new ReminderException('invalid_timezone', 'Timezone is invalid.');
        }

        try {
            $parsed = CarbonImmutable::parse($runAtLocal);
        } catch (Exception $exception) {
            throw new ReminderException('invalid_time', 'run_at_local is invalid.');
        }

        $wall = $parsed->format('Y-m-d H:i:s');

        try {
            return (new CarbonImmutable($wall, new DateTimeZone($timezone)))->utc();
        } catch (Exception $exception) {
            throw new ReminderException('invalid_time', 'run_at_local is invalid.');
        }
    }

    /**
     * @return Collection<int, Reminder>
     */
    public function listUpcoming(User $user, int $limit = 8): Collection
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->orderBy('run_at')
            ->limit(max(1, min(50, $limit)))
            ->get();
    }

    /**
     * @return list<Reminder>
     */
    public function candidatesForMutation(User $user): array
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->orderBy('run_at')
            ->limit(50)
            ->get()
            ->all();
    }

    public function isValidTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return in_array($timezone, timezone_identifiers_list(), true);
        } catch (Exception) {
            return false;
        }
    }

    public function activeCount(User $user): int
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            return 0;
        }

        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function panelFor(User $user): array
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        $telegramConnected = $this->telegramIsLinked($user);
        $webPushAvailable = $this->webPushIsAvailable($user);
        $now = CarbonImmutable::now('UTC');

        $open = Reminder::query()
            ->with(['sourceConversation:id,user_id,title', 'deliveries', 'task:id,user_id,title'])
            ->where('user_id', $user->id)
            ->whereIn('status', ReminderLifecycle::openStatuses())
            ->orderBy('run_at')
            ->limit(80)
            ->get();

        $history = Reminder::query()
            ->with(['sourceConversation:id,user_id,title', 'deliveries', 'task:id,user_id,title'])
            ->where('user_id', $user->id)
            ->whereIn('status', [
                ReminderStatus::Delivered,
                ReminderStatus::Completed,
                ReminderStatus::Cancelled,
                ReminderStatus::Failed,
            ])
            ->orderByDesc('updated_at')
            ->limit(40)
            ->get();

        $occurrences = ReminderOccurrence::query()
            ->whereHas('reminder', fn ($query) => $query->where('user_id', $user->id))
            ->with('reminder.sourceConversation')
            ->orderByDesc('run_at')
            ->limit(40)
            ->get();

        $today = [];
        $upcoming = [];
        $due = [];

        foreach ($open as $reminder) {
            $row = $this->serializeForPanel($reminder, $timezone, $telegramConnected, $webPushAvailable, $user);

            if ($row['is_due']) {
                $due[] = $row;

                continue;
            }

            if ($this->isLocalToday($reminder->run_at, $timezone, $now)) {
                $today[] = $row;

                continue;
            }

            $upcoming[] = $row;
        }

        $historyRows = $history
            ->map(fn (Reminder $reminder): array => $this->serializeForPanel($reminder, $timezone, $telegramConnected, $webPushAvailable, $user))
            ->values()
            ->all();

        foreach ($occurrences as $occurrence) {
            $parent = $occurrence->reminder;

            if ($parent === null) {
                continue;
            }

            $historyRows[] = $this->serializeOccurrence($occurrence, $parent, $timezone, $telegramConnected, $webPushAvailable, $user);
        }

        usort($historyRows, static function (array $left, array $right): int {
            return strcmp((string) ($right['sort_at'] ?? $right['run_at'] ?? ''), (string) ($left['sort_at'] ?? $left['run_at'] ?? ''));
        });

        $historyRows = array_slice($historyRows, 0, 40);

        return [
            'telegram_connected' => $telegramConnected,
            'web_push_available' => $webPushAvailable,
            'web_push_configured' => VapidConfig::isConfigured(),
            'vapid_public_key' => VapidConfig::publicKey(),
            'delivery_available' => $telegramConnected || $webPushAvailable,
            'active_count' => $this->activeCount($user),
            'today' => $today,
            'upcoming' => $upcoming,
            'due' => $due,
            'history' => $historyRows,
            'active' => array_merge($due, $today, $upcoming),
        ];
    }

    public function cancelOwned(User $user, int $reminderId): Reminder
    {
        $reminder = $this->findOwned($user, $reminderId);
        $reminder = $this->assertOwnedCancellable($user, $reminder);
        ReminderLifecycle::markCancelled($reminder, CarbonImmutable::now('UTC'));
        $reminder->save();
        $fresh = $reminder->fresh() ?? $reminder;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function completeOwned(User $user, int $reminderId): Reminder
    {
        $reminder = $this->assertOwnedCompletable($user, $this->findOwned($user, $reminderId));
        $now = CarbonImmutable::now('UTC');

        if ($reminder->isRecurring() && ReminderLifecycle::isOpen($reminder)) {
            $occurrenceAt = $reminder->run_at ?? $now;
            ReminderLifecycle::recordOccurrence($reminder, ReminderStatus::Completed, $occurrenceAt, $now);
            ReminderLifecycle::advanceRecurring($reminder, $occurrenceAt, $now, $this->recurrence);
            $this->persistPendingOccurrence($reminder, $now);
            $reminder->save();
            $fresh = $reminder->fresh() ?? $reminder;
            $this->notifyWatchers($fresh);

            return $fresh;
        }

        ReminderLifecycle::markCompleted($reminder, $now);
        $reminder->save();
        $completed = $reminder->fresh() ?? $reminder;
        $this->notifyWatchers($completed);

        return $completed;
    }

    public function snoozeOwned(User $user, int $reminderId, string $preset, ?string $customLocal = null): Reminder
    {
        $reminder = $this->assertOwnedSnoozable($user, $this->findOwned($user, $reminderId));
        $timezone = $reminder->timezone ?: (string) ($user->timezone ?: 'UTC');
        $now = CarbonImmutable::now('UTC');
        $customUtc = null;

        if ($preset === 'custom') {
            if ($customLocal === null || trim($customLocal) === '') {
                throw new ReminderException('invalid_time', 'Custom snooze time is required.');
            }

            $customUtc = $this->localWallTimeToUtc($customLocal, $timezone);

            if ($customUtc->lessThanOrEqualTo($now)) {
                throw new ReminderException('past_time', 'Reminder time is in the past.');
            }
        }

        $runAt = ReminderLifecycle::resolveSnoozeAt($reminder, $preset, $now, $customUtc);
        ReminderLifecycle::snoozeTo($reminder, $runAt, $timezone);
        $this->clearDeliveries($reminder);
        $reminder->save();
        $fresh = $reminder->fresh() ?? $reminder;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function updateOwned(
        User $user,
        int $reminderId,
        ?string $text = null,
        ?string $runAtLocal = null,
        ?string $timezone = null,
        mixed $recurrence = false,
    ): Reminder {
        $reminder = $this->assertOwnedEditable($user, $this->findOwned($user, $reminderId));
        $nextTimezone = $timezone ?? (string) $reminder->timezone;

        if ($text !== null) {
            if (trim($text) === '') {
                throw new ReminderException('empty_text', 'Reminder text is empty.');
            }

            $reminder->text = trim($text);
        }

        if ($runAtLocal !== null || $timezone !== null) {
            $local = $runAtLocal ?? (string) $reminder->original_local_time;

            if ($local === '') {
                throw new ReminderException('invalid_time', 'run_at_local is invalid.');
            }

            $runAtUtc = $this->localWallTimeToUtc($local, $nextTimezone);

            if ($runAtUtc->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
                throw new ReminderException('past_time', 'Reminder time is in the past.');
            }

            $rule = $recurrence === false
                ? ReminderRecurrenceCalculator::normalize($reminder->recurrence_rule)
                : $this->normalizeRecurrence(is_string($recurrence) ? $recurrence : null);

            ReminderLifecycle::applySchedule($reminder, $runAtUtc, $nextTimezone, $rule);
            $this->clearDeliveries($reminder);
        } elseif ($recurrence !== false) {
            $reminder->recurrence_rule = $this->normalizeRecurrence(is_string($recurrence) ? $recurrence : null);
        }

        $reminder->save();
        $fresh = $reminder->fresh() ?? $reminder;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    public function findOwned(User $user, int $reminderId): ?Reminder
    {
        return Reminder::query()
            ->where('user_id', $user->id)
            ->whereKey($reminderId)
            ->first();
    }

    public function linkOwnedTask(User $user, int $reminderId, int $taskId): Reminder
    {
        $reminder = $this->assertOwnedEditable($user, $this->findOwned($user, $reminderId));

        if ($this->ownedTaskId($user, $taskId) === null) {
            throw new ReminderException('not_found', 'Task was not found.');
        }

        $reminder->task_id = $taskId;
        $reminder->save();
        $fresh = $reminder->fresh() ?? $reminder;
        $this->notifyWatchers($fresh);

        return $fresh;
    }

    /**
     * Reminders may only reference a task owned by the same user, whatever the caller passed.
     */
    private function ownedTaskId(User $user, ?int $taskId): ?int
    {
        if ($taskId === null || $taskId <= 0) {
            return null;
        }

        $owned = Task::query()
            ->where('user_id', $user->id)
            ->whereKey($taskId)
            ->exists();

        if (! $owned) {
            try {
                Log::warning('reminder task link rejected', [
                    'user_id' => (int) $user->id,
                    'task_id' => $taskId,
                    'reason' => 'not_owned',
                ]);
            } catch (Throwable) {
            }

            return null;
        }

        return $taskId;
    }

    public function assertOwnedCancellable(User $user, ?Reminder $reminder): Reminder
    {
        $reminder = $this->assertOwnedOpen($user, $reminder, 'not_cancellable', 'This reminder cannot be cancelled.');

        return $reminder;
    }

    public function assertOwnedEditable(User $user, ?Reminder $reminder): Reminder
    {
        return $this->assertOwnedOpen($user, $reminder, 'not_editable', 'This reminder cannot be edited.');
    }

    public function assertOwnedSnoozable(User $user, ?Reminder $reminder): Reminder
    {
        return $this->assertOwnedOpen($user, $reminder, 'not_snoozable', 'This reminder cannot be snoozed.');
    }

    public function assertOwnedCompletable(User $user, ?Reminder $reminder): Reminder
    {
        $this->assertCapability($user);

        if ($reminder === null || (int) $reminder->user_id !== (int) $user->id) {
            throw new ReminderException('not_found', 'Reminder not found.');
        }

        if (! ReminderLifecycle::isCompletable($reminder)) {
            throw new ReminderException('not_completable', 'This reminder cannot be marked done.');
        }

        return $reminder;
    }

    public function markCancelled(Reminder $reminder): void
    {
        ReminderLifecycle::markCancelled($reminder, CarbonImmutable::now('UTC'));
        $this->notifyWatchers($reminder);
    }

    private function notifyWatchers(Reminder $reminder): void
    {
        try {
            app(WatcherEvaluationDispatcher::class)->afterReminderChanged($reminder);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeForPanel(
        Reminder $reminder,
        string $fallbackTimezone,
        bool $telegramConnected,
        ?bool $webPushAvailable = null,
        ?User $user = null,
    ): array {
        $timezone = $reminder->timezone ?: $fallbackTimezone;

        try {
            $local = $reminder->run_at?->setTimezone($timezone);
        } catch (Exception) {
            $local = $reminder->run_at?->utc();
            $timezone = 'UTC';
        }

        $recurrence = trim((string) ($reminder->recurrence_rule ?? ''));
        $isDue = ReminderLifecycle::isOpen($reminder)
            && $reminder->run_at !== null
            && $reminder->run_at->utc()->lessThanOrEqualTo(CarbonImmutable::now('UTC'));

        $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
        $deliveryState = $metadata['delivery_state'] ?? null;
        $pushOn = $webPushAvailable === true;

        if ($deliveryState === null && $isDue && ! $telegramConnected && ! $pushOn) {
            $deliveryState = ReminderDeliveryState::STATE_NO_CHANNEL;
        }

        $deliveryChannel = $deliveryState === ReminderDeliveryState::STATE_NO_CHANNEL
            ? null
            : ($metadata['delivery_channel'] ?? $this->inferredChannel($telegramConnected, $pushOn));

        $source = $this->sourceConversationPayload($reminder, $user);
        $task = $this->taskPayload($reminder, $user);
        $deliveryAvailable = $telegramConnected || $pushOn;
        $health = (new AutomationHealthService)->forReminder($reminder);
        $last = AutomationRun::latestFor(AutomationType::Reminder, (int) $reminder->id);

        return [
            'id' => (int) $reminder->id,
            'text' => $reminder->text,
            'status' => $reminder->status->value,
            'status_label' => HumanStatusLabel::reminderStatus($reminder->status),
            'schedule_label' => HumanMoment::label($reminder->run_at, $timezone),
            'task_label' => is_array($task) && isset($task['title'])
                ? 'По задаче «'.$task['title'].'»'
                : null,
            'problem_label' => HumanStatusLabel::reminderProblem($reminder, $deliveryAvailable),
            'last_result_label' => HumanAutomationResult::label($last),
            'automation_health' => $health->value,
            'badge' => HumanAutomationResult::healthBadge($health),
            'timezone_label' => $timezone !== $fallbackTimezone
                ? 'Время указано по '.$timezone
                : null,
            'run_at' => optional($reminder->run_at)?->toIso8601String(),
            'run_at_local' => $local?->format('Y-m-d\TH:i:sP'),
            'timezone' => $timezone,
            'original_local_time' => $reminder->original_local_time,
            'recurrence' => $recurrence !== '' ? $recurrence : null,
            'is_due' => $isDue,
            'is_occurrence' => false,
            'delivery_state' => $deliveryState,
            'delivery_channel' => $deliveryChannel,
            'deliveries' => $this->serializeDeliveries($reminder),
            'delivery_available' => $deliveryAvailable,
            'telegram_connected' => $telegramConnected,
            'web_push_available' => $pushOn,
            'cancellable' => ReminderLifecycle::isOpen($reminder),
            'editable' => ReminderLifecycle::isEditable($reminder),
            'snoozable' => ReminderLifecycle::isSnoozable($reminder),
            'completable' => ReminderLifecycle::isCompletable($reminder),
            'source_conversation' => $source,
            'task' => $task,
            'created_at' => optional($reminder->created_at)?->toIso8601String(),
            'delivered_at' => optional($reminder->delivered_at)?->toIso8601String(),
            'cancelled_at' => optional($reminder->cancelled_at)?->toIso8601String(),
            'completed_at' => optional($reminder->completed_at)?->toIso8601String(),
            'sort_at' => optional($reminder->updated_at)?->toIso8601String() ?? optional($reminder->run_at)?->toIso8601String(),
            'last_error' => $deliveryState === ReminderDeliveryState::STATE_NO_CHANNEL ? null : $reminder->last_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeOccurrence(
        ReminderOccurrence $occurrence,
        Reminder $parent,
        string $fallbackTimezone,
        bool $telegramConnected,
        bool $webPushAvailable,
        ?User $user,
    ): array {
        $row = $this->serializeForPanel($parent, $fallbackTimezone, $telegramConnected, $webPushAvailable, $user);
        $timezone = $parent->timezone ?: $fallbackTimezone;

        try {
            $local = $occurrence->run_at?->setTimezone($timezone);
        } catch (Exception) {
            $local = $occurrence->run_at?->utc();
        }

        $row['id'] = 'occurrence-'.$occurrence->id;
        $row['occurrence_id'] = (int) $occurrence->id;
        $row['parent_id'] = (int) $parent->id;
        $row['is_occurrence'] = true;
        $row['status'] = $occurrence->status->value;
        $row['run_at'] = optional($occurrence->run_at)?->toIso8601String();
        $row['run_at_local'] = $local?->format('Y-m-d\TH:i:sP');
        $row['schedule_label'] = HumanMoment::label($occurrence->run_at, $timezone);
        $row['status_label'] = HumanStatusLabel::reminderStatus($occurrence->status);
        $row['problem_label'] = null;
        $row['is_due'] = false;
        $row['cancellable'] = false;
        $row['editable'] = false;
        $row['snoozable'] = false;
        $row['completable'] = false;
        $row['delivered_at'] = optional($occurrence->delivered_at)?->toIso8601String();
        $row['completed_at'] = optional($occurrence->completed_at)?->toIso8601String();
        $row['sort_at'] = optional($occurrence->run_at)?->toIso8601String();

        return $row;
    }

    private function assertOwnedOpen(User $user, ?Reminder $reminder, string $error, string $message): Reminder
    {
        $this->assertCapability($user);

        if ($reminder === null || (int) $reminder->user_id !== (int) $user->id) {
            throw new ReminderException('not_found', 'Reminder not found.');
        }

        if (! ReminderLifecycle::isOpen($reminder)) {
            throw new ReminderException($error, $message);
        }

        return $reminder;
    }

    private function assertCapability(User $user): void
    {
        if (! $user->canUseCapability(UserCapability::REMINDERS)) {
            throw new ReminderException('capability_denied', 'Reminders are not available.');
        }
    }

    private function inferredChannel(bool $telegramConnected, bool $webPushAvailable): ?string
    {
        if ($telegramConnected && $webPushAvailable) {
            return 'both';
        }

        if ($telegramConnected) {
            return 'telegram';
        }

        if ($webPushAvailable) {
            return 'web_push';
        }

        return null;
    }

    /**
     * @return list<array{channel: string, status: string}>
     */
    private function serializeDeliveries(Reminder $reminder): array
    {
        if (! $reminder->relationLoaded('deliveries')) {
            return [];
        }

        return $reminder->deliveries
            ->map(static fn ($delivery): array => [
                'channel' => $delivery->channel->value,
                'status' => $delivery->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, title: string}|null
     */
    private function sourceConversationPayload(Reminder $reminder, ?User $user): ?array
    {
        $conversation = $reminder->sourceConversation;

        if ($conversation === null || $reminder->source_conversation_id === null) {
            return null;
        }

        if ($user !== null && (int) $conversation->user_id !== (int) $user->id) {
            return null;
        }

        $title = trim((string) $conversation->title);

        return [
            'id' => (int) $conversation->id,
            'title' => $title !== '' ? $title : 'Основной',
        ];
    }

    /**
     * @return array{id: int, title: string}|null
     */
    private function taskPayload(Reminder $reminder, ?User $user): ?array
    {
        $task = $reminder->task;

        if ($task === null || $reminder->task_id === null) {
            return null;
        }

        if ($user !== null && (int) $task->user_id !== (int) $user->id) {
            return null;
        }

        return [
            'id' => (int) $task->id,
            'title' => (string) $task->title,
        ];
    }

    private function isLocalToday(?CarbonImmutable $runAt, string $timezone, CarbonImmutable $now): bool
    {
        if ($runAt === null) {
            return false;
        }

        try {
            return $runAt->setTimezone($timezone)->toDateString() === $now->setTimezone($timezone)->toDateString();
        } catch (Exception) {
            return false;
        }
    }

    private function persistPendingOccurrence(Reminder $reminder, CarbonImmutable $now): void
    {
        $pending = $reminder->metadata['pending_occurrence'] ?? null;

        if (! is_array($pending) || ! $reminder->exists) {
            return;
        }

        ReminderOccurrence::query()->create([
            'reminder_id' => $reminder->id,
            'run_at' => CarbonImmutable::parse($pending['run_at'])->utc(),
            'status' => $pending['status'] ?? ReminderStatus::Completed->value,
            'delivered_at' => null,
            'completed_at' => $now,
            'delivery_snapshot' => $pending,
        ]);

        $metadata = is_array($reminder->metadata) ? $reminder->metadata : [];
        unset($metadata['pending_occurrence']);
        $reminder->metadata = $metadata;
    }

    private function clearDeliveries(Reminder $reminder): void
    {
        if (! $reminder->exists) {
            return;
        }

        $reminder->deliveries()->delete();
    }
}
