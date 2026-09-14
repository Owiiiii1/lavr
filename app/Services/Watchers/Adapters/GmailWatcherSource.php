<?php

namespace App\Services\Watchers\Adapters;

use App\Enums\WatcherTriggerType;
use App\Models\User;
use App\Models\Watcher;
use App\Services\Watchers\Contracts\GmailWatcherClient;
use App\Services\Watchers\Contracts\WatcherSourceAdapter;
use App\Services\Watchers\DTO\WatcherObservation;
use App\Services\Watchers\WatcherSupport;
use Carbon\CarbonImmutable;

final class GmailWatcherSource implements WatcherSourceAdapter
{
    public function __construct(
        private readonly GmailWatcherClient $gmail,
    ) {}

    public function supports(Watcher $watcher): bool
    {
        return $watcher->trigger_type === WatcherTriggerType::GmailMessage;
    }

    public function check(User $user, Watcher $watcher): array
    {
        $source = is_array($watcher->source_config) ? $watcher->source_config : [];
        unset($source['user_id']);
        if ($watcher->integration_account_id) {
            $source['integration_account_id'] = (int) $watcher->integration_account_id;
        }
        if ($watcher->project_id) {
            $source['project_id'] = (int) $watcher->project_id;
        }
        $rows = $this->gmail->search($user, $source);
        $observations = [];

        foreach (array_slice($rows, 0, max(1, (int) config('watchers.limits.max_observations', 20))) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $occurred = isset($row['occurred_at']) ? CarbonImmutable::parse((string) $row['occurred_at'])->utc() : CarbonImmutable::now('UTC');
            $observations[] = new WatcherObservation(
                sourceType: 'gmail',
                sourceId: $id,
                eventType: 'email_received',
                fingerprint: WatcherSupport::fingerprint('gmail', (string) $watcher->id, $id),
                occurredAt: $occurred,
                title: WatcherSupport::summary((string) ($row['subject'] ?? 'Email')),
                metadata: WatcherSupport::boundMetadata([
                    'thread_id' => (string) ($row['thread_id'] ?? ''),
                    'sender' => (string) ($row['sender'] ?? ($row['from'] ?? '')),
                    'subject' => WatcherSupport::summary((string) ($row['subject'] ?? '')),
                ]),
                entityId: $watcher->knowledge_entity_id,
                projectId: $watcher->project_id,
            );
        }

        return $observations;
    }
}
