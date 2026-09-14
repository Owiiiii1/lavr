<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class CreateGmailDraftTool extends GoogleGmailTool
{
    public const NAME = 'create_gmail_draft';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a Gmail draft only. Does not send. Use when the user asked to prepare a draft or a draft reply. For a reply, pass reply_to_message_id so threading headers are set. Attachments are not supported.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'to' => [
                        'type' => 'ARRAY',
                        'description' => 'Recipient emails. Required unless this is a reply and the original sender can be used.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'cc' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional CC emails.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'bcc' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional BCC emails.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'subject' => [
                        'type' => 'STRING',
                        'description' => 'Subject. Required for a new draft; optional for a reply.',
                    ],
                    'body' => [
                        'type' => 'STRING',
                        'description' => 'Plain-text body. Required.',
                    ],
                    'thread_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional Gmail thread id for a reply draft.',
                    ],
                    'in_reply_to_message_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional original Gmail message id when drafting a reply.',
                    ],
                    'reply_all' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true and this is a reply, include original To/Cc.',
                    ],
                ],
                'required' => ['body'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Create a Gmail draft. It will not be sent.';
    }

    protected function hasRequiredScope(array $scopes): bool
    {
        return $this->oauth->hasGmailComposeScope($scopes);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return $this->ok($call, $this->gmail->createDraft($this->resolveAccount($context, $call), $call->arguments));
    }
}
