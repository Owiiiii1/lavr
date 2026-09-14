<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetCalendarEventTool extends GoogleCalendarTool
{
    public const NAME = 'get_calendar_event';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads one Google Calendar event by event_id. Use after search/list to inspect the exact event before update or delete. calendar_id defaults to primary. Recurring instances use the instance id, not a series rule.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'event_id' => [
                        'type' => 'STRING',
                        'description' => 'Google event id or instance id.',
                    ],
                    'calendar_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional calendar id. Defaults to primary.',
                    ],
                ],
                'required' => ['event_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $eventId = trim((string) ($call->arguments['event_id'] ?? ''));
        if ($eventId === '') {
            throw new IntegrationException('invalid_arguments', 'event_id is required.');
        }

        $account = $this->resolveAccount($context, $call);
        $event = $this->calendar->getEvent($account, $this->calendarId($call), $eventId);

        return $this->ok($call, ['event' => $event, 'result_count' => 1]);
    }
}
