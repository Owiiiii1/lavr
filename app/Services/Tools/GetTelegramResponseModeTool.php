<?php

namespace App\Services\Tools;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Users\UserCapability;
use App\Services\Users\UserChannelPreferenceService;

final class GetTelegramResponseModeTool implements JarvisTool
{
    public const NAME = 'get_telegram_response_mode';

    public function __construct(
        private readonly UserChannelPreferenceService $preferences,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Reads how LAVR currently replies in Telegram for this user: text, voice, or auto. Use when the user asks how Telegram replies work. Never pass user_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => new \stdClass,
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::TELEGRAM_DM,
            operation: ToolOperationClass::Read,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::TELEGRAM_DM);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $mode = $this->preferences->telegramResponseMode($context->user);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'channel' => 'telegram',
            'mode' => $mode->value,
        ]);
    }
}
