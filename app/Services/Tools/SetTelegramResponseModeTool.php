<?php

namespace App\Services\Tools;

use App\Enums\TelegramResponseMode;
use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Users\UserCapability;
use App\Services\Users\UserChannelPreferenceService;

final class SetTelegramResponseModeTool implements JarvisTool
{
    public const NAME = 'set_telegram_response_mode';

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
            description: 'Sets how LAVR replies in Telegram for the current user. Call when the user asks to be answered by voice, by text, or automatically. Modes: text, voice, auto. Never pass user_id. No confirmation modal.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'mode' => [
                        'type' => 'STRING',
                        'description' => 'text: always Telegram text. voice: Telegram native voice message when possible. auto: voice note → voice reply; text → text reply.',
                        'enum' => ['text', 'voice', 'auto'],
                    ],
                ],
                'required' => ['mode'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::TELEGRAM_DM,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::TELEGRAM_DM);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $mode = TelegramResponseMode::tryFromInput($call->arguments['mode'] ?? null);

        if ($mode === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_mode',
            ]);
        }

        $this->preferences->setTelegramResponseMode($context->user, $mode);

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'channel' => 'telegram',
            'mode' => $mode->value,
        ]);
    }
}
