<?php

namespace App\Services\Watchers\Clients;

use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\IntegrationAccountResolver;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Contracts\CalendarWatcherClient;
use App\Services\Watchers\WatcherSupport;
use Throwable;

final class LiveCalendarWatcherClient implements CalendarWatcherClient
{
    public function __construct(
        private readonly GoogleCalendarService $calendar,
        private readonly IntegrationAccountService $accounts,
        private readonly IntegrationAccountResolver $resolver,
    ) {}

    public function events(User $user, array $source): array
    {
        if (! $user->canUseCapability(UserCapability::GOOGLE_CALENDAR)) {
            throw new IntegrationException('google_not_connected', 'Google Calendar is not connected.');
        }

        $accounts = $this->resolveAccounts($user, $source);
        if ($accounts === []) {
            throw new IntegrationException('google_not_connected', 'Google Calendar is not connected.');
        }

        $calendarId = (string) ($source['calendar_id'] ?? 'primary');
        $eventId = trim((string) ($source['event_id'] ?? ''));
        $rows = [];
        $healthy = 0;

        foreach ($accounts as $account) {
            try {
                if ($eventId !== '') {
                    $event = $this->calendar->getEvent($account, $calendarId, $eventId);
                    $healthy++;
                    $rows[] = $this->compact($event);

                    continue;
                }

                $result = $this->calendar->listEvents($account, $calendarId, [
                    'max_results' => 20,
                ]);
                $healthy++;
            } catch (Throwable) {
                continue;
            }

            foreach ($result['events'] ?? [] as $event) {
                if (is_array($event)) {
                    $rows[] = $this->compact($event);
                }
            }
        }

        if ($healthy === 0) {
            throw new IntegrationException('google_unavailable', 'Google Calendar is currently unavailable.');
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<IntegrationAccount>
     */
    private function resolveAccounts(User $user, array $source): array
    {
        $accountId = isset($source['integration_account_id']) ? (int) $source['integration_account_id'] : 0;
        if ($accountId > 0) {
            return [$this->accounts->getAccount($user, $accountId, 'google')];
        }

        $projectId = isset($source['project_id']) ? (int) $source['project_id'] : 0;
        if ($projectId > 0) {
            $bound = $this->resolver->forProject($user, $projectId, 'calendar');
            if ($bound->isNotEmpty()) {
                return $bound->all();
            }
        }

        return $this->resolver->enabledFor($user, 'calendar')->all();
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function compact(array $event): array
    {
        return [
            'id' => (string) ($event['id'] ?? ''),
            'title' => WatcherSupport::summary((string) ($event['title'] ?? '')),
            'start' => (string) ($event['start'] ?? ''),
            'status' => (string) ($event['status'] ?? ''),
            'etag' => (string) ($event['etag'] ?? ($event['updated'] ?? '')),
            'updated' => (string) ($event['updated'] ?? ''),
        ];
    }
}
