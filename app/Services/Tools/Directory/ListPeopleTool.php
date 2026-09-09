<?php

namespace App\Services\Tools\Directory;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Directory\DirectoryService;
use App\Services\Directory\Exceptions\DirectoryException;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;

final class ListPeopleTool implements JarvisTool
{
    public const NAME = 'list_people';

    public function __construct(
        private readonly DirectoryService $directory,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'List canonical People, optionally filtered by role, project, or status. Use for “who works on Chicago”.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING'],
                    'role' => ['type' => 'STRING', 'description' => 'employee, client, partner, …'],
                    'project_id' => ['type' => 'INTEGER'],
                    'status' => ['type' => 'STRING'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::PEOPLE, operation: ToolOperationClass::Read);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::PEOPLE);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        try {
            $people = $this->directory->listPeople(
                $context->user,
                isset($call->arguments['query']) ? (string) $call->arguments['query'] : null,
                isset($call->arguments['role']) ? (string) $call->arguments['role'] : null,
                isset($call->arguments['project_id']) ? (int) $call->arguments['project_id'] : null,
                isset($call->arguments['status']) ? (string) $call->arguments['status'] : null,
            );
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'people' => $people->map(fn ($person): array => $this->directory->serializePersonSummary($person))->values()->all(),
        ]);
    }
}
