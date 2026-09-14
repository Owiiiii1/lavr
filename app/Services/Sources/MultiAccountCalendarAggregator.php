<?php

namespace App\Services\Sources;

use App\Enums\IntegrationHealth;
use App\Models\IntegrationAccount;
use App\Models\User;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleCalendarService;
use App\Services\Integrations\IntegrationAccountService;
use Throwable;

final class MultiAccountCalendarAggregator
{
    public function __construct(
        private readonly IntegrationAccountResolver $resolver,
        private readonly GoogleCalendarService $calendar,
        private readonly IntegrationAccountService $accounts,
        private readonly SourceRouter $router,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array{events: list<array<string, mixed>>, unavailable: list<array<string, mixed>>, semantics: string, truncated: bool}
     */
    public function listEvents(
        User $user,
        array $options,
        ?int $accountId = null,
        ?int $projectId = null,
        string $calendarId = 'primary',
    ): array {
        $accounts = $this->targetAccounts($user, $accountId, $projectId);
        if ($accounts === []) {
            $enabled = $this->accounts->listEnabled($user, 'google');
            if ($enabled->isNotEmpty() && ($accountId === null || $accountId < 1) && ($projectId === null || $projectId < 1)) {
                throw new IntegrationException('calendar_scope_required', 'Calendar permission is required.');
            }

            throw new IntegrationException('google_not_connected', 'Google Calendar is not connected.');
        }

        $events = [];
        $unavailable = [];
        $healthy = 0;
        $truncated = false;

        foreach ($accounts as $account) {
            try {
                $result = $this->calendar->listEvents($account, $calendarId, $options);
                $this->accounts->recordSuccess($account);
                $healthy++;
                if (($result['truncated'] ?? false) === true) {
                    $truncated = true;
                }
                foreach ($result['events'] ?? [] as $event) {
                    if (! is_array($event)) {
                        continue;
                    }
                    $routed = $this->router->resolveProject($user, [
                        'source_type' => 'google_calendar',
                        'source_id' => $account->id,
                        'title' => $event['title'] ?? null,
                    ]);
                    $events[] = array_merge($event, [
                        'account_id' => $account->id,
                        'account_label' => $account->label(),
                        'project_id' => $routed['project_id'],
                        'project_confidence' => $routed['confidence'],
                    ]);
                }
            } catch (Throwable $exception) {
                $code = $exception instanceof IntegrationException ? $exception->error : 'google_unavailable';
                $this->accounts->recordError($account, $code);
                $unavailable[] = [
                    'account_id' => $account->id,
                    'label' => $account->label(),
                    'reason' => $code,
                    'state' => 'unknown',
                    'health' => $account->health instanceof IntegrationHealth ? $account->health->value : null,
                ];
            }
        }

        return [
            'events' => $this->dedupe($events),
            'unavailable' => $unavailable,
            'semantics' => $this->semantics($healthy, $unavailable, $events),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>>
     */
    public function dedupe(array $events): array
    {
        $seen = [];
        $out = [];
        foreach ($events as $event) {
            $key = mb_strtolower(trim((string) ($event['ical_uid'] ?? '')));
            if ($key === '') {
                $key = mb_strtolower(trim((string) ($event['title'] ?? '')).'|'.(string) ($event['start'] ?? '').'|'.(string) ($event['organizer'] ?? ''));
            }
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $event;
        }

        return $out;
    }

    /**
     * @return list<IntegrationAccount>
     */
    private function targetAccounts(User $user, ?int $accountId, ?int $projectId): array
    {
        if ($accountId !== null && $accountId > 0) {
            return [$this->resolver->resolve($user, 'calendar', $accountId, null)];
        }

        if ($projectId !== null && $projectId > 0) {
            $bound = $this->resolver->forProject($user, $projectId, 'calendar');
            if ($bound->isNotEmpty()) {
                return $bound->all();
            }
        }

        return $this->resolver->enabledFor($user, 'calendar')->all();
    }

    /**
     * @param  list<array<string, mixed>>  $unavailable
     * @param  list<array<string, mixed>>  $events
     */
    private function semantics(int $healthy, array $unavailable, array $events): string
    {
        if ($healthy === 0 && $unavailable !== []) {
            return 'unknown';
        }

        if ($unavailable !== []) {
            return $events === [] ? 'partial_empty' : 'partial';
        }

        return $events === [] ? 'empty' : 'ok';
    }
}
