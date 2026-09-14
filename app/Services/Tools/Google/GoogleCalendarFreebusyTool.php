<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GoogleCalendarFreebusyTool extends GoogleCalendarTool
{
    public const NAME = 'google_calendar_freebusy';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Returns busy intervals for Google calendars in a bounded range. Use when the user asks if they are free or before scheduling. Default calendar is primary. Core does not decide free/busy wording — inspect busy ranges. Max range is configured (about 31 days).',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'time_min' => [
                        'type' => 'STRING',
                        'description' => 'Required ISO 8601 range start.',
                    ],
                    'time_max' => [
                        'type' => 'STRING',
                        'description' => 'Required ISO 8601 range end.',
                    ],
                    'calendar_ids' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional calendar ids. Defaults to primary. Count is capped.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'Optional IANA timezone. Owner timezone is the fallback.',
                    ],
                ],
                'required' => ['time_min', 'time_max'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $account = $this->resolveAccount($context, $call);
        $timezone = $this->times->ownerTimezone($context->user);
        if (filled($call->arguments['timezone'] ?? null)) {
            $timezone = $this->times->assertValidTimezone((string) $call->arguments['timezone']);
        }

        $start = $this->times->parseDateTime((string) ($call->arguments['time_min'] ?? ''), $timezone);
        $end = $this->times->parseDateTime((string) ($call->arguments['time_max'] ?? ''), $timezone);
        $this->times->assertOrder($start, $end);
        $this->times->assertDayRange((int) $start->diffInDays($end), (int) config('google_calendar.max_freebusy_days', 31));

        $calendarIds = $call->arguments['calendar_ids'] ?? [];
        if (! is_array($calendarIds)) {
            throw new IntegrationException('invalid_arguments', 'calendar_ids must be a list.');
        }

        $calendarIds = array_values(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $calendarIds,
        )));

        $result = $this->calendar->freeBusy(
            $account,
            $start->toIso8601String(),
            $end->toIso8601String(),
            $calendarIds,
            $timezone,
        );

        return $this->ok($call, $result);
    }
}
