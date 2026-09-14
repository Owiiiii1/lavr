<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGmailThreadTool extends GoogleGmailTool
{
    public const NAME = 'get_gmail_thread';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads a Gmail thread as chronological bounded messages. Core does not summarize; you summarize for the user. Total characters are capped. Use for "покажи переписку".',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'thread_id' => [
                        'type' => 'STRING',
                        'description' => 'Required Gmail thread id.',
                    ],
                    'max_messages' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max messages in the thread. Core caps this.',
                    ],
                ],
                'required' => ['thread_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $threadId = trim((string) ($call->arguments['thread_id'] ?? ''));
        if ($threadId === '') {
            throw new IntegrationException('invalid_arguments', 'thread_id is required.');
        }

        return $this->ok($call, $this->gmail->getThread(
            $this->resolveAccount($context, $call),
            $threadId,
            isset($call->arguments['max_messages']) ? (int) $call->arguments['max_messages'] : null,
        ));
    }
}
