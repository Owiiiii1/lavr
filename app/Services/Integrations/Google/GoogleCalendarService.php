<?php

namespace App\Services\Integrations\Google;

use App\Models\IntegrationAccount;
use App\Services\Integrations\Exceptions\IntegrationException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GoogleCalendarService
{
    public function __construct(
        private readonly GoogleCredentialService $credentials,
        private readonly CalendarTimeParser $times,
    ) {}

    /**
     * @return array{calendars: list<array<string, mixed>>, truncated: bool}
     */
    public function listCalendars(IntegrationAccount $account, ?int $maxResults = null): array
    {
        $limit = $this->bound($maxResults, (int) config('google_calendar.max_calendars', 20));
        $items = [];
        $pageToken = null;
        $truncated = false;

        do {
            $query = [
                'maxResults' => min(250, $limit - count($items) + 1),
            ];
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->get($account, '/users/me/calendarList', $query);
            $page = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($page as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }

                $items[] = $this->mapCalendar($raw);
            }

            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null);

        if ($pageToken !== null) {
            $truncated = true;
        }

        return [
            'calendars' => $items,
            'truncated' => $truncated,
            'result_count' => count($items),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{events: list<array<string, mixed>>, truncated: bool, result_count: int}
     */
    public function listEvents(IntegrationAccount $account, string $calendarId, array $options): array
    {
        return $this->collectEvents($account, $calendarId, $options, (int) config('google_calendar.max_events', 25));
    }

    /**
     * @return array<string, mixed>
     */
    public function getEvent(IntegrationAccount $account, string $calendarId, string $eventId): array
    {
        $payload = $this->get($account, $this->eventPath($calendarId, $eventId));

        return $this->mapEvent($payload, $calendarId);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{events: list<array<string, mixed>>, truncated: bool, result_count: int}
     */
    public function searchEvents(IntegrationAccount $account, string $calendarId, string $query, array $options): array
    {
        $options['q'] = $query;

        return $this->collectEvents($account, $calendarId, $options, (int) config('google_calendar.max_search_results', 15));
    }

    /**
     * @param  list<string>  $calendarIds
     * @return array{calendars: array<string, array{busy: list<array{start: string, end: string}>}>, has_busy: bool}
     */
    public function freeBusy(
        IntegrationAccount $account,
        string $timeMin,
        string $timeMax,
        array $calendarIds,
        ?string $timezone = null,
    ): array {
        $maxCalendars = (int) config('google_calendar.max_calendars', 20);
        $calendarIds = array_values(array_unique(array_filter(array_map(
            static fn (string $id): string => trim($id),
            $calendarIds,
        ))));

        if ($calendarIds === []) {
            $calendarIds = [(string) config('google_calendar.default_calendar', 'primary')];
        }

        if (count($calendarIds) > $maxCalendars) {
            $calendarIds = array_slice($calendarIds, 0, $maxCalendars);
        }

        $body = [
            'timeMin' => $timeMin,
            'timeMax' => $timeMax,
            'items' => array_map(static fn (string $id): array => ['id' => $id], $calendarIds),
        ];

        if (is_string($timezone) && $timezone !== '') {
            $body['timeZone'] = $this->times->assertValidTimezone($timezone);
        }

        $payload = $this->post($account, '/freeBusy', $body, retrySafe: true);
        $calendars = [];
        $hasBusy = false;

        foreach ((array) ($payload['calendars'] ?? []) as $id => $data) {
            if (! is_array($data)) {
                continue;
            }

            $busy = [];
            foreach ((array) ($data['busy'] ?? []) as $interval) {
                if (! is_array($interval)) {
                    continue;
                }

                $start = (string) ($interval['start'] ?? '');
                $end = (string) ($interval['end'] ?? '');
                if ($start === '' || $end === '') {
                    continue;
                }

                $busy[] = ['start' => $start, 'end' => $end];
                $hasBusy = true;
            }

            $calendars[(string) $id] = ['busy' => $busy];
        }

        return [
            'calendars' => $calendars,
            'has_busy' => $hasBusy,
            'timezone' => $timezone,
            'result_count' => count($calendars),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function createEvent(
        IntegrationAccount $account,
        string $calendarId,
        array $event,
        string $clientEventId,
        string $sendUpdates = 'none',
    ): array {
        $event['id'] = $clientEventId;
        $query = ['sendUpdates' => $this->sendUpdates($sendUpdates)];

        try {
            $payload = $this->post($account, $this->eventsPath($calendarId), $event, $query, retrySafe: false);
        } catch (IntegrationException $exception) {
            if ($exception->error === 'calendar_conflict') {
                return $this->getEvent($account, $calendarId, $clientEventId);
            }

            throw $exception;
        }

        return $this->mapEvent($payload, $calendarId);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function updateEvent(
        IntegrationAccount $account,
        string $calendarId,
        string $eventId,
        array $fields,
        ?string $etag = null,
        string $sendUpdates = 'none',
    ): array {
        $headers = [];
        if (filled($etag)) {
            $headers['If-Match'] = str_starts_with((string) $etag, '"') ? (string) $etag : '"'.$etag.'"';
        }

        $payload = $this->patch(
            $account,
            $this->eventPath($calendarId, $eventId),
            $fields,
            ['sendUpdates' => $this->sendUpdates($sendUpdates)],
            $headers,
        );

        return $this->mapEvent($payload, $calendarId);
    }

    public function deleteEvent(
        IntegrationAccount $account,
        string $calendarId,
        string $eventId,
        string $sendUpdates = 'none',
    ): void {
        $this->delete(
            $account,
            $this->eventPath($calendarId, $eventId),
            ['sendUpdates' => $this->sendUpdates($sendUpdates)],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{events: list<array<string, mixed>>, truncated: bool, result_count: int}
     */
    private function collectEvents(IntegrationAccount $account, string $calendarId, array $options, int $defaultLimit): array
    {
        $limit = $this->bound(isset($options['max_results']) ? (int) $options['max_results'] : null, $defaultLimit);
        $items = [];
        $pageToken = null;
        $truncated = false;

        do {
            $query = [
                'singleEvents' => ($options['single_events'] ?? true) ? 'true' : 'false',
                'maxResults' => min(250, $limit - count($items) + 1),
            ];

            if (! empty($options['time_min'])) {
                $query['timeMin'] = (string) $options['time_min'];
            }
            if (! empty($options['time_max'])) {
                $query['timeMax'] = (string) $options['time_max'];
            }
            if (! empty($options['q'])) {
                $query['q'] = (string) $options['q'];
            }
            if (($options['order_by'] ?? null) === 'startTime' && ($options['single_events'] ?? true)) {
                $query['orderBy'] = 'startTime';
            }
            if (is_string($pageToken) && $pageToken !== '') {
                $query['pageToken'] = $pageToken;
            }

            $payload = $this->get($account, $this->eventsPath($calendarId), $query);
            $page = is_array($payload['items'] ?? null) ? $payload['items'] : [];

            foreach ($page as $raw) {
                if (! is_array($raw)) {
                    continue;
                }

                if (count($items) >= $limit) {
                    $truncated = true;
                    break 2;
                }

                $items[] = $this->mapEvent($raw, $calendarId);
            }

            $pageToken = is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null;
        } while ($pageToken !== null);

        if ($pageToken !== null) {
            $truncated = true;
        }

        return [
            'events' => $items,
            'truncated' => $truncated,
            'result_count' => count($items),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(IntegrationAccount $account, string $path, array $query = []): array
    {
        return $this->send($account, 'GET', $path, query: $query, retrySafe: true);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function post(
        IntegrationAccount $account,
        string $path,
        array $body,
        array $query = [],
        bool $retrySafe = false,
    ): array {
        return $this->send($account, 'POST', $path, $body, $query, retrySafe: $retrySafe);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function patch(
        IntegrationAccount $account,
        string $path,
        array $body,
        array $query = [],
        array $headers = [],
    ): array {
        return $this->send($account, 'PATCH', $path, $body, $query, $headers, retrySafe: false);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function delete(IntegrationAccount $account, string $path, array $query = []): void
    {
        $this->send($account, 'DELETE', $path, query: $query, retrySafe: false);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    private function send(
        IntegrationAccount $account,
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        array $headers = [],
        bool $retrySafe = false,
    ): array {
        $token = $this->credentials->getValidAccessToken($account);
        $url = rtrim((string) config('google_calendar.api_base'), '/').$path;
        $retries = $retrySafe ? max(0, (int) config('google_calendar.get_retries', 1)) : 0;

        $request = $this->http()
            ->withToken($token)
            ->withHeaders($headers)
            ->retry($retries, 200, throw: false);

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $query),
                'POST' => $request->post($query === [] ? $url : $url.'?'.http_build_query($query), $body),
                'PATCH' => $request->patch($query === [] ? $url : $url.'?'.http_build_query($query), $body),
                'DELETE' => $request->delete($query === [] ? $url : $url.'?'.http_build_query($query)),
                default => throw new IntegrationException('google_unavailable', 'Unsupported Calendar method.'),
            };
        } catch (IntegrationException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->logFailure($method, 'google_unavailable');
            throw new IntegrationException('google_unavailable', 'Google Calendar is unavailable.', true);
        }

        if ($method === 'DELETE' && ($response->successful() || $response->status() === 204 || $response->status() === 410)) {
            return [];
        }

        if (! $response->successful()) {
            $this->failFromResponse($response, $path);
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('google_calendar.timeout', 10))
            ->connectTimeout((int) config('google_calendar.connect_timeout', 5))
            ->acceptJson()
            ->asJson();
    }

    private function failFromResponse(Response $response, string $path): never
    {
        $status = $response->status();
        $code = $this->normalizeError($status, $response, $path);
        $this->logFailure('http', $code, $status);

        throw new IntegrationException(
            $code,
            'Google Calendar request failed.',
            in_array($code, ['google_rate_limited', 'google_unavailable'], true),
        );
    }

    private function normalizeError(int $status, Response $response, string $path): string
    {
        $reason = strtolower((string) ($response->json('error.status') ?? $response->json('error.errors.0.reason') ?? ''));

        if ($status === 401 || str_contains($reason, 'unauth') || str_contains($reason, 'invalid_grant')) {
            return 'google_calendar_not_connected';
        }

        if ($status === 403 && (str_contains($reason, 'scope') || str_contains($reason, 'insufficient'))) {
            return 'google_calendar_scope_missing';
        }

        if ($status === 403) {
            return 'calendar_forbidden';
        }

        if ($status === 404) {
            return str_contains($path, '/events/') ? 'event_not_found' : 'calendar_not_found';
        }

        if (in_array($status, [409, 412], true)) {
            return 'calendar_conflict';
        }

        if ($status === 429) {
            return 'google_rate_limited';
        }

        if ($status >= 500) {
            return 'google_unavailable';
        }

        return 'google_unavailable';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapCalendar(array $raw): array
    {
        return [
            'id' => (string) ($raw['id'] ?? ''),
            'summary' => (string) ($raw['summary'] ?? $raw['id'] ?? ''),
            'primary' => (bool) ($raw['primary'] ?? false),
            'access_role' => isset($raw['accessRole']) ? (string) $raw['accessRole'] : null,
            'timezone' => isset($raw['timeZone']) ? (string) $raw['timeZone'] : null,
            'selected' => isset($raw['selected']) ? (bool) $raw['selected'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function mapEvent(array $raw, string $calendarId): array
    {
        $start = is_array($raw['start'] ?? null) ? $raw['start'] : [];
        $end = is_array($raw['end'] ?? null) ? $raw['end'] : [];
        $allDay = isset($start['date']) && ! isset($start['dateTime']);
        $maxDescription = (int) config('google_calendar.max_description_chars', 2000);
        $description = isset($raw['description']) ? (string) $raw['description'] : null;

        if (is_string($description) && mb_strlen($description) > $maxDescription) {
            $description = mb_substr($description, 0, $maxDescription);
        }

        $attendees = [];
        $maxAttendees = (int) config('google_calendar.max_attendees', 20);
        foreach (array_slice((array) ($raw['attendees'] ?? []), 0, $maxAttendees) as $attendee) {
            if (! is_array($attendee) || ! filled($attendee['email'] ?? null)) {
                continue;
            }

            $attendees[] = [
                'email' => (string) $attendee['email'],
                'status' => isset($attendee['responseStatus']) ? (string) $attendee['responseStatus'] : null,
            ];
        }

        $recurrence = null;
        if (! empty($raw['recurrence']) && is_array($raw['recurrence'])) {
            $recurrence = 'recurring';
        } elseif (! empty($raw['recurringEventId'])) {
            $recurrence = 'instance';
        }

        $etag = isset($raw['etag']) ? trim((string) $raw['etag'], '"') : null;

        return [
            'id' => (string) ($raw['id'] ?? ''),
            'calendar_id' => $calendarId,
            'title' => (string) ($raw['summary'] ?? ''),
            'description' => $description,
            'location' => isset($raw['location']) ? (string) $raw['location'] : null,
            'start' => $allDay ? (string) ($start['date'] ?? '') : (string) ($start['dateTime'] ?? ''),
            'end' => $allDay ? (string) ($end['date'] ?? '') : (string) ($end['dateTime'] ?? ''),
            'all_day' => $allDay,
            'timezone' => (string) ($start['timeZone'] ?? $end['timeZone'] ?? ''),
            'attendees' => $attendees,
            'organizer' => isset($raw['organizer']['email']) ? (string) $raw['organizer']['email'] : null,
            'status' => isset($raw['status']) ? (string) $raw['status'] : null,
            'html_link' => isset($raw['htmlLink']) ? (string) $raw['htmlLink'] : null,
            'ical_uid' => isset($raw['iCalUID']) ? (string) $raw['iCalUID'] : null,
            'recurrence' => $recurrence,
            'etag' => $etag,
        ];
    }

    private function eventsPath(string $calendarId): string
    {
        return '/calendars/'.$this->encodeCalendarId($calendarId).'/events';
    }

    private function eventPath(string $calendarId, string $eventId): string
    {
        return $this->eventsPath($calendarId).'/'.rawurlencode($eventId);
    }

    private function encodeCalendarId(string $calendarId): string
    {
        $calendarId = trim($calendarId);
        if ($calendarId === '') {
            $calendarId = (string) config('google_calendar.default_calendar', 'primary');
        }

        return rawurlencode($calendarId);
    }

    private function sendUpdates(string $value): string
    {
        return in_array($value, ['all', 'externalOnly', 'none'], true) ? $value : 'none';
    }

    private function bound(?int $requested, int $configured): int
    {
        $limit = $configured > 0 ? $configured : 1;

        if ($requested === null || $requested < 1) {
            return $limit;
        }

        return min($requested, $limit);
    }

    private function logFailure(string $action, string $code, ?int $status = null): void
    {
        Log::info('google calendar', [
            'provider' => 'google',
            'action' => $action,
            'success' => false,
            'error_code' => $code,
            'http_status' => $status,
        ]);
    }
}
