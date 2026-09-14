<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class GetGmailMessageTool extends GoogleGmailTool
{
    public const NAME = 'get_gmail_message';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads one Gmail message by id. Returns normalized headers, a bounded plain-text body, labels, and attachment metadata. Does not download attachments. Use after search or list when the user wants the exact content.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'message_id' => [
                        'type' => 'STRING',
                        'description' => 'Required Gmail message id from search or list.',
                    ],
                ],
                'required' => ['message_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Read;
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $messageId = trim((string) ($call->arguments['message_id'] ?? ''));
        if ($messageId === '') {
            throw new IntegrationException('invalid_arguments', 'message_id is required.');
        }

        $message = $this->gmail->getMessage($this->resolveAccount($context, $call), $messageId);

        return $this->ok($call, [
            'message' => $message,
            'truncated' => (bool) ($message['truncated'] ?? false),
            'result_count' => 1,
        ]);
    }
}
