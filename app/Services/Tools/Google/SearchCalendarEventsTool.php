<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Sources\MultiAccountCalendarAggregator;
use App\Services\Tools\ToolExecutionContext;

final class SearchCalendarEventsTool extends GoogleCalendarTool
{
    public const NAME = 'search_calendar_events';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches events on one Google calendar (primary by default) by text query. Always prefer a date range. If omitted, Core applies a bounded default window. Do not search all calendars implicitly. If several events match, ask the user or call get_calendar_event before update/delete.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Required search text, for example a person or title fragment.',
                    ],
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
                        'description' => 'Optional ISO 8601 range start.',
                    ],
                    'time_max' => [
                        'type' => 'STRING',
                        'description' => 'Optional ISO 8601 range end.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max results. Core caps this.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'Optional IANA timezone. Owner timezone is the fallback.',
                    ],
                ],
                'required' => ['query'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));
        if ($query === '') {
            throw new IntegrationException('invalid_arguments', 'query is required.');
        }

        $timezone = $this->times->ownerTimezone($context->user);
        if (filled($call->arguments['timezone'] ?? null)) {
            $timezone = $this->times->assertValidTimezone((string) $call->arguments['timezone']);
        }

        $past = (int) config('google_calendar.default_search_past_days', 90);
        $future = (int) config('google_calendar.default_search_future_days', 365);
        $minRaw = trim((string) ($call->arguments['time_min'] ?? ''));
        $maxRaw = trim((string) ($call->arguments['time_max'] ?? ''));

        $start = $minRaw !== ''
            ? $this->times->parseDateTime($minRaw, $timezone)
            : now($timezone)->subDays($past);
        $end = $maxRaw !== ''
            ? $this->times->parseDateTime($maxRaw, $timezone)
            : now($timezone)->addDays($future);

        $this->times->assertOrder($start, $end);

        $accountId = isset($call->arguments['account_id']) ? (int) $call->arguments['account_id'] : 0;
        $projectId = isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : 0;

        $aggregated = app(MultiAccountCalendarAggregator::class)->listEvents(
            $context->user,
            [
                'time_min' => $start->toIso8601String(),
                'time_max' => $end->toIso8601String(),
                'max_results' => isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : null,
                'single_events' => true,
                'order_by' => 'startTime',
                'q' => $query,
            ],
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
}
