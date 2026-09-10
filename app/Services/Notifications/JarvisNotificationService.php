<?php

namespace App\Services\Notifications;

use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use App\Models\JarvisNotification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Reminders\Contracts\SendsWebPush;
use App\Services\Reminders\PushPayloadBuilder;
use App\Services\Reminders\PushSubscriptionService;
use App\Services\Reminders\VapidConfig;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

final class JarvisNotificationService
{
    public function __construct(
        private readonly NotificationInbox $inbox = new NotificationInbox,
        private readonly NotificationUrlPolicy $urls = new NotificationUrlPolicy,
        private readonly PushPayloadBuilder $payloads = new PushPayloadBuilder,
        private readonly PushSubscriptionService $subscriptions = new PushSubscriptionService,
        private readonly ?SendsWebPush $webPush = null,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        User $user,
        JarvisNotificationType $type,
        string $title,
        string $body,
        string $dedupeKey,
        ?string $sourceType = null,
        ?int $sourceId = null,
        ?string $actionUrl = null,
        array $metadata = [],
        bool $aiPhrased = false,
        bool $sendPush = true,
    ): ?JarvisNotification {
        $existing = JarvisNotification::query()
            ->where('user_id', $user->id)
            ->where('dedupe_key', $dedupeKey)
            ->first();

        if (! $this->inbox->shouldCreate($existing)) {
            return null;
        }

        $safeUrl = $this->urls->sanitize($actionUrl);
        $now = CarbonImmutable::now('UTC');
        $notification = $this->inbox->make(
            $user,
            $type,
            Str::limit($title, (int) config('productivity.notifications.title_max', 160), '…'),
            Str::limit($body, (int) config('productivity.notifications.body_max', 800), '…'),
            $dedupeKey,
            $now,
            $this->severityFor($type),
            $sourceType,
            $sourceId,
            $safeUrl,
            $this->boundedMetadata($metadata, $dedupeKey, $now),
            $aiPhrased,
        );

        try {
            $notification->save();
        } catch (Throwable) {
            return JarvisNotification::query()
                ->where('user_id', $user->id)
                ->where('dedupe_key', $dedupeKey)
                ->first();
        }

        if ($sendPush && $type !== JarvisNotificationType::ReminderDue) {
            $this->maybePush($user, $notification);
        }

        return $notification;
    }

    public function unreadCount(User $user): int
    {
        return JarvisNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    public function panelFor(User $user, bool $unreadOnly = false): array
    {
        $query = JarvisNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('dismissed_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(80);

        if ($unreadOnly) {
            $query->whereNull('read_at');
        }

        $items = $query->get()
            ->map(fn (JarvisNotification $notification): array => $this->serialize($notification, $user))
            ->values()
            ->all();

        return [
            'unread_count' => $this->unreadCount($user),
            'items' => $items,
        ];
    }

    public function markReadOwned(User $user, int $id): JarvisNotification
    {
        $notification = $this->requireOwned($user, $id);
        $this->inbox->markRead($notification, CarbonImmutable::now('UTC'));
        $notification->save();

        return $notification;
    }

    public function dismissOwned(User $user, int $id): JarvisNotification
    {
        $notification = $this->requireOwned($user, $id);
        $this->inbox->dismiss($notification, CarbonImmutable::now('UTC'));
        $notification->save();

        return $notification;
    }

    public function markAllRead(User $user): int
    {
        $now = CarbonImmutable::now('UTC');

        return JarvisNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->whereNull('dismissed_at')
            ->update(['read_at' => $now]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(JarvisNotification $notification, User $user): array
    {
        $url = $this->urls->sanitize($notification->action_url);

        return [
            'id' => (int) $notification->id,
            'type' => $notification->type->value,
            'title' => $notification->title,
            'body' => $notification->body,
            'severity' => $notification->severity->value,
            'source_type' => $notification->source_type,
            'source_id' => $notification->source_id,
            'action_url' => $url,
            'read_at' => optional($notification->read_at)?->toIso8601String(),
            'dismissed_at' => optional($notification->dismissed_at)?->toIso8601String(),
            'occurred_at' => optional($notification->occurred_at)?->toIso8601String(),
            'unread' => $notification->isUnread(),
            'ai_phrased' => (bool) $notification->ai_phrased,
        ];
    }

    public function requireOwned(User $user, int $id): JarvisNotification
    {
        $notification = JarvisNotification::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->first();

        if ($notification === null) {
            abort(404);
        }

        return $notification;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function boundedMetadata(array $metadata, string $dedupeKey, CarbonImmutable $now): array
    {
        $allowed = [
            'trigger' => $metadata['trigger'] ?? null,
            'source_id' => $metadata['source_id'] ?? null,
            'dedupe_key' => $dedupeKey,
            'generated_at' => $now->utc()->toIso8601String(),
            'ai_phrased' => (bool) ($metadata['ai_phrased'] ?? false),
            'mode' => $metadata['mode'] ?? null,
            'watcher_id' => $metadata['watcher_id'] ?? null,
            'occurrence_id' => $metadata['occurrence_id'] ?? null,
            'scheduled_report_id' => $metadata['scheduled_report_id'] ?? null,
            'executive_brief_id' => $metadata['executive_brief_id'] ?? null,
            'slot_key' => $metadata['slot_key'] ?? null,
            'pending_action' => $metadata['pending_action'] ?? null,
        ];

        return array_filter($allowed, static fn (mixed $value): bool => $value !== null);
    }

    private function severityFor(JarvisNotificationType $type): JarvisNotificationSeverity
    {
        return match ($type) {
            JarvisNotificationType::TaskOverdue, JarvisNotificationType::ProactiveSuggestion, JarvisNotificationType::CommitmentOverdue => JarvisNotificationSeverity::Urgent,
            JarvisNotificationType::TaskDue, JarvisNotificationType::WatcherTriggered, JarvisNotificationType::CommitmentDueSoon => JarvisNotificationSeverity::Warning,
            default => JarvisNotificationSeverity::Info,
        };
    }

    private function maybePush(User $user, JarvisNotification $notification): void
    {
        if ($this->webPush === null || ! VapidConfig::isConfigured()) {
            return;
        }

        $limit = max(20, (int) config('productivity.notifications.push_body_limit', 120));
        $url = $this->urls->sanitize($notification->action_url) ?? $this->urls->workspacePath($user, 'notifications=1');
        $payload = [
            'title' => 'LAVR',
            'body' => Str::limit((string) $notification->body, $limit, '…'),
            'url' => $url,
            'timestamp' => optional($notification->occurred_at)?->toIso8601String() ?? CarbonImmutable::now('UTC')->toIso8601String(),
        ];

        if (! $this->payloads->isAllowlisted($url)) {
            return;
        }

        foreach ($this->subscriptions->activeFor($user) as $subscription) {
            if (! $subscription instanceof PushSubscription) {
                continue;
            }

            try {
                $this->webPush->send($subscription, $payload);
            } catch (Throwable) {
            }
        }
    }
}
