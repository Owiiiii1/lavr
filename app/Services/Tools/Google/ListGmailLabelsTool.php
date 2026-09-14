<?php

namespace App\Services\Tools\Google;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Tools\ToolExecutionContext;

final class ListGmailLabelsTool extends GoogleGmailTool
{
    public const NAME = 'list_gmail_labels';

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Lists Gmail system and user labels with ids. Use before applying a custom label so you pass a real label id, not a guessed name.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => (object) [],
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
        return $this->ok($call, $this->gmail->listLabels($this->resolveAccount($context, $call)));
    }
}
