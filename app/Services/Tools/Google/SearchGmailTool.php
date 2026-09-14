<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Tools\ToolExecutionContext;

final class SearchGmailTool extends GoogleGmailTool
{
    public const NAME = 'search_gmail';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches the owner Gmail mailbox by sender, topic, date, or Gmail search syntax. Returns compact headers and snippets only, not full bodies. Use when the user asks to find mail. Do not dump the whole mailbox.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Required Gmail search query, for example from:name subject:contract newer_than:7d.',
                    ],
                    'account_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional integration account id. Omit to use the latest enabled mailbox.',
                    ],
                    'project_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional project id to use that project mailbox binding.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional max results. Core caps this.',
                    ],
                    'include_spam_trash' => [
                        'type' => 'BOOLEAN',
                        'description' => 'Include spam and trash. Default false.',
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

        return $this->ok($call, $this->gmail->searchMessages($this->resolveAccount($context, $call), $query, [
            'max_results' => isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : null,
            'include_spam_trash' => (bool) ($call->arguments['include_spam_trash'] ?? false),
        ]));
    }
}
