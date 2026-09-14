<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGoogleCalendarsTool extends GoogleCalendarTool
{
    public const NAME = 'list_google_calendars';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists Google calendars available to the owner. Use to inspect calendar ids, names, primary calendar, access role, and timezone before listing or creating events. Do not use for reminders (create_reminder).',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max calendars. Core caps this.',
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
        $account = $this->resolveAccount($context, $call);
        $max = isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : null;
        $result = $this->calendar->listCalendars($account, $max);

        return $this->ok($call, $result);
    }
}
