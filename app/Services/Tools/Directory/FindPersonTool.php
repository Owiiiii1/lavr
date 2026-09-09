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

final class FindPersonTool implements JarvisTool
{
    public const NAME = 'find_person';

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
            description: 'Find canonical People records by name, email, or Telegram username. Use this before Knowledge when the Owner asks who someone is.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'query' => ['type' => 'STRING', 'description' => 'Name, email, or Telegram username.'],
                ],
                'required' => ['query'],
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
        $query = trim((string) ($call->arguments['query'] ?? $call->arguments['name'] ?? ''));

        if ($query === '') {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => 'invalid_arguments']);
        }

        try {
            $people = $this->directory->findPeople($context->user, $query);
        } catch (DirectoryException $exception) {
            return ToolResult::failure($call->id, $this->name(), ['success' => false, 'error' => $exception->error]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'people' => $people->map(fn ($person): array => $this->directory->serializePersonSummary($person))->values()->all(),
        ]);
    }
}
