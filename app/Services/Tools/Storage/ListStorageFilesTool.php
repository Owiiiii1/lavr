<?php

namespace App\Services\Tools\Storage;

use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Storage\StoredFileConfig;
use App\Services\Tools\ToolExecutionContext;

final class ListStorageFilesTool extends StorageTool
{
    public const NAME = 'list_storage_files';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists the current user’s persistent LAVR Storage files. Use when the user asks what files they have stored. Does not return file contents.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => [
                        'type' => 'STRING',
                        'description' => 'Optional filename or summary search.',
                    ],
                    'extension' => [
                        'type' => 'STRING',
                        'description' => 'Optional extension filter, for example php or log.',
                    ],
                    'max_results' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional cap. Core enforces a hard limit.',
                    ],
                ],
            ],
        );
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $query = trim((string) ($call->arguments['query'] ?? ''));
        $extension = trim((string) ($call->arguments['extension'] ?? ''));
        $limit = isset($call->arguments['max_results']) ? (int) $call->arguments['max_results'] : StoredFileConfig::searchResultLimit();
        $files = $this->search->searchFiles(
            $context->user,
            $query,
            $extension !== '' ? $extension : null,
            $limit,
        );

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'count' => count($files),
            'truncated' => count($files) >= StoredFileConfig::searchResultLimit(),
            'files' => $files,
        ]);
    }
}
