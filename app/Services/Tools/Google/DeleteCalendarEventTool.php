<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class DeleteCalendarEventTool extends GoogleCalendarTool
{
    public const NAME = 'delete_calendar_event';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Deletes one Google Calendar event after the exact event_id is resolved. Always requires persisted user confirmation. Do not delete a fuzzy first match. Recurring series-wide deletes are out of scope; use the explicit instance id. send_updates defaults to none.',
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
        return ToolOperationClass::Destructive;
    }

    protected function confirmationHint(): ?string
    {
        return 'Confirm deletion of this Google Calendar event.';
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $eventId = trim((string) ($call->arguments['event_id'] ?? ''));
        if ($eventId === '') {
            throw new IntegrationException('invalid_arguments', 'event_id is required.');
        }

        $account = $this->resolveAccount($context, $call);
        $this->calendar->deleteEvent(
            $account,
            $this->calendarId($call),
            $eventId,
            $this->sendUpdates($call),
        );

        return $this->ok($call, [
            'deleted' => true,
            'event_id' => $eventId,
            'calendar_id' => $this->calendarId($call),
            'result_count' => 1,
        ]);
    }
}
