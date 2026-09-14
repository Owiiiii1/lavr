<?php

namespace App\Services\Tools\Sources;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Sources\MultiAccountGmailSearch;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class SearchEmailTool implements JarvisTool
{
    public const NAME = 'search_email';

    public function __construct(
        private readonly MultiAccountGmailSearch $search,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Searches enabled Gmail mailboxes. Omit account_id to search all enabled mailboxes. Pass project_id to limit to that project source bindings (Chicago, Finance). Results include account label, sender, date, and project if resolved. If a mailbox is blocked, other mailboxes still return. UNKNOWN is not EMPTY.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING', 'description' => 'Gmail search query, for example from:Sony newer_than:7d.'],
                    'account_id' => ['type' => 'INTEGER', 'description' => 'Optional specific Google account id.'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Optional project id to use bound mailboxes.'],
                    'max_results' => ['type' => 'INTEGER', 'description' => 'Optional max results per mailbox.'],
                ],
                'required' => ['query'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::GMAIL, operation: ToolOperationClass::Read, provider: 'google');
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::GMAIL);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));
        if ($query === '') {
            throw new IntegrationException('invalid_arguments', 'query is required.');
        }

        $result = $this->search->search(
            $context->user,
            $query,
            isset($call->arguments['account_id']) ? (int) $call->arguments['account_id'] : null,
            isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
            isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : 15,
        );

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            ...$result,
            'freshness' => $this->freshnessLine($result),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function freshnessLine(array $result): string
    {
        $unavailable = is_array($result['unavailable'] ?? null) ? $result['unavailable'] : [];
        if ($unavailable === []) {
            return '';
        }

        $labels = array_values(array_filter(array_map(
            static fn (array $row): string => trim((string) ($row['label'] ?? '')),
            $unavailable,
        )));

        if ($labels === []) {
            return 'Some mailboxes are currently unavailable.';
        }

        return implode(', ', $labels).' currently unavailable.';
    }
}
