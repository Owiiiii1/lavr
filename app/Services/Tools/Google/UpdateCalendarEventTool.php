<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class UpdateCalendarEventTool extends GoogleCalendarTool
{
    public const NAME = 'update_calendar_event';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Updates an existing Google Calendar event after the exact event_id is resolved (search then get). PATCH only supplied fields. Do not update a fuzzy first match. Do not author recurrence rules. For recurring events use the explicit instance id. Pass etag from get/list when available. Recurring series-wide edits are out of scope.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'event_id' => [
                        'type' => 'STRING',
                        'description' => 'Required Google event id or instance id.',
                    ],
                    'calendar_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional calendar id. Defaults to primary.',
                    ],
                    'title' => [
                        'type' => 'STRING',
                        'description' => 'New title if changing.',
                    ],
                    'start' => [
                        'type' => 'STRING',
                        'description' => 'New start ISO 8601 datetime or date.',
                    ],
                    'end' => [
                        'type' => 'STRING',
                        'description' => 'New end ISO 8601 datetime or date.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'Optional IANA timezone for supplied times.',
                    ],
                    'description' => [
                        'type' => 'STRING',
                        'description' => 'New description if changing.',
                    ],
                    'location' => [
                        'type' => 'STRING',
                        'description' => 'New location if changing.',
                    ],
                    'attendees' => [
                        'type' => 'ARRAY',
                        'description' => 'Replacement attendee emails if changing.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'all_day' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true, treat start/end as dates.',
                    ],
                    'etag' => [
                        'type' => 'STRING',
                        'description' => 'Optional etag from get/list for If-Match.',
                    ],
                    'send_updates' => [
                        'type' => 'STRING',
                        'description' => 'none, all, or externalOnly. Default none.',
                    ],
                ],
                'required' => ['event_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $eventId = trim((string) ($call->arguments['event_id'] ?? ''));
        if ($eventId === '') {
            throw new IntegrationException('invalid_arguments', 'event_id is required.');
        }

        $account = $this->resolveAccount($context, $call);
        $timezone = $this->times->ownerTimezone($context->user);
        if (filled($call->arguments['timezone'] ?? null)) {
            $timezone = $this->times->assertValidTimezone((string) $call->arguments['timezone']);
        }

        $fields = [];

        if (array_key_exists('title', $call->arguments)) {
            $fields['summary'] = $this->boundedText(
                $call->arguments['title'],
                (int) config('google_calendar.max_title_chars', 200),
                required: true,
            );
        }

        if (array_key_exists('description', $call->arguments)) {
            $fields['description'] = $this->boundedText(
                $call->arguments['description'],
                (int) config('google_calendar.max_description_chars', 2000),
            ) ?? '';
        }

        if (array_key_exists('location', $call->arguments)) {
            $fields['location'] = $this->boundedText(
                $call->arguments['location'],
                (int) config('google_calendar.max_location_chars', 500),
            ) ?? '';
        }

        if (array_key_exists('attendees', $call->arguments)) {
            $fields['attendees'] = array_map(
                static fn (string $email): array => ['email' => $email],
                $this->attendees($call),
            );
        }

        $hasStart = array_key_exists('start', $call->arguments);
        $hasEnd = array_key_exists('end', $call->arguments);
        $allDay = (bool) ($call->arguments['all_day'] ?? false);

        if ($hasStart || $hasEnd) {
            if (! $hasStart || ! $hasEnd) {
                throw new IntegrationException('invalid_arguments', 'start and end must be supplied together.');
            }

            if ($allDay) {
                $startDate = $this->times->parseDate((string) $call->arguments['start'], $timezone);
                $endDate = $this->times->parseDate((string) $call->arguments['end'], $timezone);
                if ($endDate <= $startDate) {
                    throw new IntegrationException('invalid_arguments', 'Start must be before end.');
                }
                $fields['start'] = ['date' => $startDate];
                $fields['end'] = ['date' => $endDate];
            } else {
                $start = $this->times->parseDateTime((string) $call->arguments['start'], $timezone);
                $end = $this->times->parseDateTime((string) $call->arguments['end'], $timezone);
                $this->times->assertOrder($start, $end);
                $fields['start'] = $this->times->googleDateTime($start, $timezone);
                $fields['end'] = $this->times->googleDateTime($end, $timezone);
            }
        }

        if ($fields === []) {
            throw new IntegrationException('invalid_arguments', 'No fields to update.');
        }

        $etag = trim((string) ($call->arguments['etag'] ?? ''));
        $updated = $this->calendar->updateEvent(
            $account,
            $this->calendarId($call),
            $eventId,
            $fields,
            $etag !== '' ? $etag : null,
            $this->sendUpdates($call),
        );

        return $this->ok($call, [
            'event' => $updated,
            'result_count' => 1,
        ]);
    }
}
