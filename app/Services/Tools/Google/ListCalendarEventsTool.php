<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Sources\MultiAccountCalendarAggregator;
use App\Services\Tools\ToolExecutionContext;

final class ListCalendarEventsTool extends GoogleCalendarTool
{
    public const NAME = 'list_calendar_events';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists events on a Google calendar in a bounded ISO 8601 time range. Default calendar is primary. time_min and time_max should be ISO 8601; naive datetimes use the owner timezone. All-day events stay dates (all_day=true), not midnight meetings. Use for "what is on my calendar". Prefer a narrow range. Do not dump unbounded history.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'calendar_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional calendar id. Defaults to primary.',
                    ],
                    'account_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional Google integration account id.',
                    ],
                    'project_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional project id to use bound calendars.',
                    ],
                    'time_min' => [
                        'type' => 'STRING',
                        'description' => 'Range start ISO 8601 datetime or date.',
                    ],
                    'time_max' => [
                        'type' => 'STRING',
                        'description' => 'Range end ISO 8601 datetime or date.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max events. Core caps this.',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional free-text filter.',
                    ],
                    'single_events' => [
                        'type' => 'BOOLEAN',
                        'description' => 'Expand recurring instances. Default true.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'Optional IANA timezone. Owner timezone is authoritative fallback.',
                    ],
                ],
                'required' => [],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $timezone = $this->times->ownerTimezone($context->user);
        if (filled($call->arguments['timezone'] ?? null)) {
            $timezone = $this->times->assertValidTimezone((string) $call->arguments['timezone']);
        }

        [$timeMin, $timeMax] = $this->range($call, $timezone);
        $options = [
            'time_min' => $timeMin,
            'time_max' => $timeMax,
            'max_results' => isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : null,
            'single_events' => ($call->arguments['single_events'] ?? true) !== false,
            'order_by' => 'startTime',
        ];

        $query = trim((string) ($call->arguments['query'] ?? ''));
        if ($query !== '') {
            $options['q'] = $query;
        }

        $accountId = isset($call->arguments['account_id']) ? (int) $call->arguments['account_id'] : 0;
        $projectId = isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : 0;

        $aggregated = app(MultiAccountCalendarAggregator::class)->listEvents(
            $context->user,
            $options,
            $accountId > 0 ? $accountId : null,
            $projectId > 0 ? $projectId : null,
            $this->calendarId($call),
        );

        return $this->ok($call, [
            'events' => $aggregated['events'],
            'unavailable' => $aggregated['unavailable'],
            'semantics' => $aggregated['semantics'],
            'truncated' => (bool) ($aggregated['truncated'] ?? false),
            'result_count' => count($aggregated['events']),
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(ToolCall $call, string $timezone): array
    {
        $maxDays = (int) config('google_calendar.max_list_range_days', 90);
        $defaultFuture = (int) config('google_calendar.default_list_future_days', 7);

        $minRaw = trim((string) ($call->arguments['time_min'] ?? ''));
        $maxRaw = trim((string) ($call->arguments['time_max'] ?? ''));

        $start = $minRaw !== ''
            ? $this->times->parseDateTime($minRaw, $timezone)
            : now($timezone)->startOfDay();
        $end = $maxRaw !== ''
            ? $this->times->parseDateTime($maxRaw, $timezone)
            : $start->copy()->addDays(max(1, $defaultFuture));

        $this->times->assertOrder($start, $end);
        $this->times->assertDayRange((int) $start->diffInDays($end), $maxDays);

        return [$start->toIso8601String(), $end->toIso8601String()];
    }
}
