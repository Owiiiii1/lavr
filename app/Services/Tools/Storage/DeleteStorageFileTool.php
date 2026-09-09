<?php

namespace App\Services\Tools\Storage;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class DeleteStorageFileTool extends StorageTool
{
    public const NAME = 'delete_storage_file';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Deletes one persistent Storage file after explicit user confirmation. Do not delete on a fuzzy name match.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'file_id' => [
                        'type' => 'STRING',
                        'description' => 'Storage file public_id UUID.',
                    ],
                ],
                'required' => ['file_id'],
            ],
        );
    }

    protected function operation(): ToolOperationClass
    {
        return ToolOperationClass::Destructive;
    }

    protected function confirmationHint(): ?string
    {
        return 'Delete this file from LAVR Storage. This cannot be undone.';
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return parent::isAvailable($context) && $context->user->isOwner();
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $id = trim((string) ($call->arguments['file_id'] ?? ''));

        if ($id === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        $file = $this->files->findOwnedByPublicId($context->user, $id);

        if ($file === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'not_found',
            ]);
        }

        $this->files->delete($context->user, $file);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'deleted' => true,
            'file_id' => $id,
        ]);
    }
}
