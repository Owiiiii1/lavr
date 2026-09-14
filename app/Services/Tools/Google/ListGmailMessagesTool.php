<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGmailMessagesTool extends GoogleGmailTool
{
    public const NAME = 'list_gmail_messages';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists recent Gmail messages. Default is INBOX. Use unread=true or is:unread for new mail. Bounded. Prefer this for "есть новые письма" and inbox overview. Use search_gmail for a specific sender or topic.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'mailbox' => [
                        'type' => 'STRING',
                        'description' => 'Optional mailbox/filter such as INBOX, SENT, ALL. Default INBOX.',
                    ],
                    'label_ids' => [
                        'type' => 'ARRAY',
                        'description' => 'Optional Gmail label ids from list_gmail_labels or known system labels.',
                        'items' => ['type' => 'STRING'],
                    ],
                    'unread' => [
                        'type' => 'BOOLEAN',
                        'description' => 'If true, only unread messages (is:unread).',
                    ],
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional extra Gmail query.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max messages. Core caps this.',
                    ],
                    'newer_than' => [
                        'type' => 'STRING',
                        'description' => 'Optional Gmail newer_than value, for example 7d.',
                    ],
                    'after' => [
                        'type' => 'STRING',
                        'description' => 'Optional Gmail after date.',
                    ],
                    'before' => [
                        'type' => 'STRING',
                        'description' => 'Optional Gmail before date.',
                    ],
                    'include_spam_trash' => [
                        'type' => 'BOOLEAN',
                        'description' => 'Include spam and trash. Default false.',
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
        return $this->ok($call, $this->gmail->listMessages($this->resolveAccount($context, $call), [
            'mailbox' => $call->arguments['mailbox'] ?? null,
            'filter' => $call->arguments['filter'] ?? null,
            'label_ids' => $call->arguments['label_ids'] ?? [],
            'unread' => (bool) ($call->arguments['unread'] ?? false),
            'query' => $call->arguments['query'] ?? null,
            'max_results' => isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : null,
            'newer_than' => $call->arguments['newer_than'] ?? null,
            'after' => $call->arguments['after'] ?? null,
            'before' => $call->arguments['before'] ?? null,
            'include_spam_trash' => (bool) ($call->arguments['include_spam_trash'] ?? false),
        ]));
    }
}
