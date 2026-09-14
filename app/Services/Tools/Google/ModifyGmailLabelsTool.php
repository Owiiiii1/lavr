<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class ModifyGmailLabelsTool extends GoogleGmailTool
{
    public const NAME = 'modify_gmail_labels';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Adds or removes Gmail labels on a message or thread. Mark read by removing UNREAD. Mark unread by adding UNREAD. Archive by removing INBOX. Do not trash or permanently delete. Pass only label ids from list_gmail_labels or known system labels.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'message_id' => [
                        'type' => 'STRING',
                        'description' => 'Gmail message id. Provide this or thread_id.',
                    ],
                    'thread_id' => [
                        'type' => 'STRING',
                        'description' => 'Gmail thread id. Provide this or message_id.',
                    ],
                    'add_label_ids' => [
                        'type' => 'ARRAY',
                        'description' => 'Label ids to add.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'remove_label_ids' => [
                        'type' => 'ARRAY',
                        'description' => 'Label ids to remove.',
                        'items' => ['type' => 'STRING'],
                    ],
                ],
                'required' => [],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Write;
    }

    protected function confirmationHint(): ?string
    {
        return 'Change Gmail labels on the identified mail.';
    }

    protected function hasRequiredScope(array $scopes): bool
    {
        return $this->oauth->hasGmailModifyScope($scopes);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $messageId = trim((string) ($call->arguments['message_id'] ?? ''));
        $threadId = trim((string) ($call->arguments['thread_id'] ?? ''));
        if ($messageId === '' && $threadId === '') {
            throw new IntegrationException('invalid_arguments', 'message_id or thread_id is required.');
        }

        return $this->ok($call, $this->gmail->modifyLabels(
            $this->resolveAccount($context, $call),
            $messageId !== '' ? $messageId : null,
            $threadId !== '' ? $threadId : null,
            is_array($call->arguments['add_label_ids'] ?? null) ? $call->arguments['add_label_ids'] : [],
            is_array($call->arguments['remove_label_ids'] ?? null) ? $call->arguments['remove_label_ids'] : [],
        ));
    }
}
