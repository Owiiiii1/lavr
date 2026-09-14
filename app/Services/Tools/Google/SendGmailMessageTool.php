<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class SendGmailMessageTool extends GoogleGmailTool
{
    public const NAME = 'send_gmail_message';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Sends a Gmail message. This is an external action and always requires persisted user confirmation before send. Use for a new email or a reply. For a reply pass reply_to_message_id so threadId, In-Reply-To, and References are set correctly. Do not invent recipients. Attachments are not supported. Prefer create_gmail_draft when the user only asked to prepare text.',
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
                        'description' => 'Subject. Required for a new message; optional for a reply.',
                    ],
                    'body' => [
                        'type' => 'STRING',
                        'description' => 'Plain-text body. Required.',
                    ],
                    'thread_id' => [
                        'type' => 'STRING',
                        'description' => 'Optional Gmail thread id for a reply.',
                    ],
                    'reply_to_message_id' => [
                        'type' => 'STRING',
                        'description' => 'Original Gmail message id when sending a reply.',
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

    protected function alwaysConfirm(): bool
    {
        return true;
    }

    protected function confirmationHint(): ?string
    {
        return 'Send this email. Confirm to deliver it now.';
    }

    protected function hasRequiredScope(array $scopes): bool
    {
        return $this->oauth->hasGmailSendScope($scopes);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        return $this->ok($call, $this->gmail->sendMessage($this->resolveAccount($context, $call), $call->arguments));
    }
}
