<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\CalendarEventIdempotency;
use App\Services\Tools\ToolExecutionContext;
use Illuminate\Support\Carbon;

final class CreateCalendarEventTool extends GoogleCalendarTool
{
    public const NAME = 'create_calendar_event';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a new Google Calendar event only when the user wants a new meeting. Do not use for reminders (create_reminder). Do not invent Google Meet links. Recurring rules are not supported. Default calendar is primary. Naive times use the owner timezone. send_updates is none unless the user explicitly asked to invite people. If attendees are present and the user asked to invite them, set send_updates=all.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'calendar_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional calendar id. Defaults to primary.',
                    ],
                    'title' => [
                        'type' => 'STRING',
                        'description' => 'Required event title.',
                    ],
                    'start' => [
                        'type' => 'STRING',
                        'description' => 'Required ISO 8601 start datetime or date.',
                    ],
                    'end' => [
                        'type' => 'STRING',
                        'description' => 'End ISO 8601 datetime or date. Required unless duration_minutes is set.',
                    ],
                    'duration_minutes' => [
                        'type' => 'INTEGER',
                        'description' => 'Duration in minutes when end is omitted. Must be positive.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'Optional IANA timezone. Owner timezone is the fallback.',
                    ],
                    'description' => [
                        'type' => 'STRING',
                        'description' => 'Optional description. Length is capped.',
                    ],
                    'location' => [
                        'type' => 'STRING',
                        'description' => 'Optional location. Length is capped.',
                    ],
                    'attendees' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional attendee emails.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'all_day' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true, start/end are dates, not midnight meetings.',
                    ],
                    'send_updates' => [
                        'type' => 'STRING',
                        'description' => 'none, all, or externalOnly. Default none.',
                    ],
                ],
                'required' => ['title', 'start'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $account = $this->resolveAccount($context, $call);
        $timezone = $this->times->ownerTimezone($context->user);
        if (filled($call->arguments['timezone'] ?? null)) {
            $timezone = $this->times->assertValidTimezone((string) $call->arguments['timezone']);
        }

        $title = $this->boundedText(
            $call->arguments['title'] ?? '',
            (int) config('google_calendar.max_title_chars', 200),
            required: true,
        );
        $description = $this->boundedText(
            $call->arguments['description'] ?? null,
            (int) config('google_calendar.max_description_chars', 2000),
        );
        $location = $this->boundedText(
            $call->arguments['location'] ?? null,
            (int) config('google_calendar.max_location_chars', 500),
        );

        $allDay = (bool) ($call->arguments['all_day'] ?? false);
        $event = ['summary' => $title];

        if ($description !== null) {
            $event['description'] = $description;
        }
        if ($location !== null) {
            $event['location'] = $location;
        }

        if ($allDay) {
            $startDate = $this->times->parseDate((string) ($call->arguments['start'] ?? ''), $timezone);
            $endRaw = trim((string) ($call->arguments['end'] ?? ''));
            $endDate = $endRaw !== ''
                ? $this->times->parseDate($endRaw, $timezone)
                : Carbon::parse($startDate, $timezone)->addDay()->toDateString();

            if ($endDate <= $startDate) {
                $endDate = Carbon::parse($startDate, $timezone)->addDay()->toDateString();
            }

            $event['start'] = ['date' => $startDate];
            $event['end'] = ['date' => $endDate];
        } else {
            $start = $this->times->parseDateTime((string) ($call->arguments['start'] ?? ''), $timezone);
            $endRaw = trim((string) ($call->arguments['end'] ?? ''));
            if ($endRaw !== '') {
                $end = $this->times->parseDateTime($endRaw, $timezone);
            } else {
                $minutes = (int) ($call->arguments['duration_minutes'] ?? 0);
                if ($minutes < 1) {
                    throw new IntegrationException('invalid_arguments', 'end or a positive duration_minutes is required.');
                }
                $end = $start->copy()->addMinutes($minutes);
            }

            $this->times->assertOrder($start, $end);
            $event['start'] = $this->times->googleDateTime($start, $timezone);
            $event['end'] = $this->times->googleDateTime($end, $timezone);
        }

        $attendees = $this->attendees($call);
        if ($attendees !== []) {
            $event['attendees'] = array_map(static fn (string $email): array => ['email' => $email], $attendees);
        }

        $eventId = CalendarEventIdempotency::googleEventId(
            (int) $context->user->id,
            (int) $context->conversation->id,
            $call->id,
        );

        $created = $this->calendar->createEvent(
            $account,
            $this->calendarId($call),
            $event,
            $eventId,
            $this->sendUpdates($call),
        );

        return $this->ok($call, [
            'event' => $created,
            'result_count' => 1,
        ]);
    }
}
