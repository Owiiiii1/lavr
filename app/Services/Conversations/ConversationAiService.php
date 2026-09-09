<?php

namespace App\Services\Conversations;

use App\Enums\AiRoleKey;
use App\Enums\ConversationKind;
use App\Enums\MessageChannel;
use App\Enums\MessageRole;
use App\Enums\MessageType;
use App\Enums\ToolConfirmationStatus;
use App\Models\AiRoleSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Ai\AgentToolLoop;
use App\Services\Ai\AgentTurnTrace;
use App\Services\Ai\AiConfigurationResolver;
use App\Services\Ai\AiFailureFallback;
use App\Services\Ai\Contracts\AiChatGateway;
use App\Services\Ai\DTO\AiChatResponse;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Ai\Exceptions\AiConfigurationException;
use App\Services\ChatAttachments\Exceptions\ChatAttachmentException;
use App\Services\Context\ContextDiagnosticsLogger;
use App\Services\Memory\MemoryTurnDispatcher;
use App\Services\Tools\ConfirmationIntentParser;
use App\Services\Tools\ToolConfirmationService;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolRegistry;
use App\Services\Voice\VoiceSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ConversationAiService
{
    public const PAIRING_GREETING_EVENT = 'Пользователь только что подключил LAVR. Поприветствуй его и коротко представься.';

    public const ONBOARDING_GREETING_EVENT = 'Начни знакомство: поприветствуй пользователя и мягко спроси, как тебя называть. Не используй анкету. Не завершай знакомство в этом первом сообщении.';

    public const AI_FAILURE = 'Сейчас не удалось сформировать ответ. Попробуй ещё раз.';

    public const VISION_NOT_SUPPORTED = 'Этот AI-провайдер не принимает изображения. Смените модель в Admin или отправьте текст.';

    public const MAX_TOOL_ROUNDS = 8;

    public const PENDING_STALE_SECONDS = 25;

    public function __construct(
        private readonly AiConfigurationResolver $resolver,
        private readonly ConversationContextBuilder $contextBuilder,
        private readonly MessagePersistenceService $messages,
        private readonly AiChatGateway $gateway,
        private readonly ToolRegistry $tools,
        private readonly MemoryTurnDispatcher $memoryTurns,
        private readonly ToolConfirmationService $confirmations,
        private readonly ConfirmationIntentParser $confirmationIntent,
        private readonly AgentToolLoop $toolLoop,
        private readonly ContextDiagnosticsLogger $contextLogs,
        private readonly VoiceSettingsService $voiceSettings,
        private readonly AiFailureFallback $failureFallback,
    ) {}

    public function completeUserTurn(Message $inbound): ConversationAiTurnResult
    {
        $inbound->loadMissing(['user', 'conversation']);

        if ($inbound->conversation?->kind !== ConversationKind::Personal) {
            return new ConversationAiTurnResult(skipped: true);
        }

        $existing = $this->existingAssistantReply($inbound);

        if ($existing !== null) {
            return new ConversationAiTurnResult(skipped: true, assistantMessage: $existing);
        }

        $status = $this->processingStatus($inbound);

        if ($status === 'completed') {
            return new ConversationAiTurnResult(skipped: true);
        }

        if ($status === 'failed') {
            return new ConversationAiTurnResult(skipped: true);
        }

        if ($status === 'pending' && $this->pendingIsFresh($inbound)) {
            return new ConversationAiTurnResult(skipped: true);
        }

        return $this->runTurn(
            user: $inbound->user ?? $inbound->conversation->user,
            conversation: $inbound->conversation,
            inbound: $inbound,
            applicationEvent: $this->voicePresentationHint($inbound),
        );
    }

    public function greetAfterPairing(User $user, Conversation $conversation): ConversationAiTurnResult
    {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Assistant)
            ->where('metadata->ai->event', 'pairing_greeting')
            ->first();

        if ($existing !== null) {
            return new ConversationAiTurnResult(skipped: true, assistantMessage: $existing);
        }

        return $this->runTurn(
            user: $user,
            conversation: $conversation,
            inbound: null,
            applicationEvent: self::PAIRING_GREETING_EVENT,
            eventName: 'pairing_greeting',
        );
    }

    public function greetOnboarding(User $user, Conversation $conversation): ConversationAiTurnResult
    {
        $existing = Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', MessageRole::Assistant)
            ->where('metadata->ai->event', 'onboarding_greeting')
            ->first();

        if ($existing !== null) {
            return new ConversationAiTurnResult(skipped: true, assistantMessage: $existing);
        }

        return $this->runTurn(
            user: $user,
            conversation: $conversation,
            inbound: null,
            applicationEvent: self::ONBOARDING_GREETING_EVENT,
            eventName: 'onboarding_greeting',
        );
    }

    private function runTurn(
        User $user,
        Conversation $conversation,
        ?Message $inbound,
        ?string $applicationEvent,
        ?string $eventName = null,
    ): ConversationAiTurnResult {
        $startedAt = microtime(true);
        $configuration = $this->resolver->resolveConversation($user);
        $trace = AgentTurnTrace::start($user->id, $conversation->id, $inbound?->id);

        if ($inbound !== null) {
            $this->markInbound($inbound, 'pending');
        }

        try {
            $this->assertReady($configuration);

            if ($inbound !== null) {
                $inbound->loadMissing(['attachments', 'storedFiles']);
            }

            if ($this->inboundHasImages($inbound) && ! $this->gateway->supportsVision($configuration)) {
                return $this->completeWithoutVision($conversation, $inbound, $configuration, $startedAt, $eventName);
            }

            $userText = $inbound?->body;
            $presenceCheck = TurnIsolation::isPresenceCheck($userText);

            if ($presenceCheck) {
                $trace->toolsDisabledForTurn = true;
                $applicationEvent = $applicationEvent === null
                    ? TurnIsolation::presenceHint()
                    : $applicationEvent."\n\n".TurnIsolation::presenceHint();
            } elseif (TurnIsolation::isRepeatRequest($userText)) {
                $applicationEvent = $applicationEvent === null
                    ? TurnIsolation::repeatHint()
                    : $applicationEvent."\n\n".TurnIsolation::repeatHint();
            }

            $intent = $this->confirmationIntent->parse($userText);
            $toolContext = new ToolExecutionContext(
                user: $user,
                conversation: $conversation,
                inbound: $inbound,
                channel: $inbound?->channel?->value,
                explicitUserCommand: true,
                confirmationIntent: $intent,
            );

            if ($inbound !== null && $intent !== null) {
                $applied = $this->confirmations->applyInboundIntent($user, $conversation, $toolContext, $this->tools);
                if ($applied['handled'] && is_string($applied['note']) && $applied['note'] !== '') {
                    $applicationEvent = $applicationEvent === null
                        ? $applied['note']
                        : $applicationEvent."\n\n".$applied['note'];
                }
            }

            $toolDefinitions = ($this->gateway->supportsTools($configuration) && ! $presenceCheck)
                ? $this->tools->definitionsFor($toolContext)
                : [];

            $context = $this->contextBuilder->build(
                $user,
                $conversation,
                $configuration,
                $inbound,
                $applicationEvent,
                $toolDefinitions,
            );
            $diagnostics = $context['diagnostics'] ?? [];
            $toolContext = new ToolExecutionContext(
                user: $toolContext->user,
                conversation: $toolContext->conversation,
                inbound: $toolContext->inbound,
                channel: $toolContext->channel,
                explicitUserCommand: $toolContext->explicitUserCommand,
                confirmationIntent: $toolContext->confirmationIntent,
                bypassConfirmation: $toolContext->bypassConfirmation,
                budgets: $toolContext->budgets,
                working: $context['working'] ?? null,
            );

            $toolResults = [];
            $parameters = is_array($configuration->parameters) ? $configuration->parameters : [];

            try {
                $response = $this->toolLoop->run(
                    $configuration,
                    $context['system_prompt'],
                    $context['messages'],
                    $toolDefinitions,
                    $toolContext,
                    $trace,
                    $toolResults,
                    $diagnostics,
                    $userText,
                );
            } catch (Throwable $exception) {
                if ($this->toolLoop->hasUsefulResults($toolResults)) {
                    try {
                        $response = $this->toolLoop->synthesize(
                            $configuration,
                            $context['system_prompt'],
                            $context['messages'],
                            $parameters,
                            $toolResults,
                            $trace,
                            $userText,
                            $diagnostics,
                        );
                    } catch (Throwable $synthesisException) {
                        $exception = $synthesisException;
                        $response = null;
                    }
                } else {
                    $response = null;
                }

                if ($response === null) {
                    $fallback = $this->failureFallback->resolve(
                        $exception,
                        $toolResults,
                        $userText,
                    );

                    if ($fallback === null) {
                        throw $exception;
                    }

                    $trace->finalOutcome = $this->fallbackOutcome($fallback, $toolResults);

                    try {
                        Log::warning('AI response failed; using safe fallback reply', [
                            'configuration' => $configuration->roleKey()->value,
                            'provider' => $configuration->provider,
                            'model' => $configuration->model,
                            'error_class' => $exception::class,
                            'error_message' => $exception->getMessage(),
                            'tool_results_count' => count($toolResults),
                        ]);
                    } catch (Throwable) {
                    }

                    $response = new AiChatResponse(
                        text: $fallback,
                        provider: (string) $configuration->provider,
                        model: (string) $configuration->model,
                        finishReason: 'fallback',
                        metadata: [
                            'fallback_error_class' => $exception::class,
                        ],
                    );
                } else {
                    $trace->finalOutcome = $trace->partialRecovery ? 'partial' : 'answer';
                }
            }

            if ($trace->finalOutcome === 'answer' && $trace->partialRecovery) {
                $trace->finalOutcome = 'partial';
            }

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $roleKey = $configuration->roleKey()->value;
            $this->contextLogs->log($user->id, $conversation->id, $diagnostics);
            $this->logTrace($trace);

            $pendingConfirmation = $this->pendingConfirmationFromResults($toolResults);
            $metadata = [
                'ai' => [
                    'configuration' => $roleKey,
                    'provider' => $response->provider,
                    'model' => $response->model,
                    'latency_ms' => $latencyMs,
                    'prompt_tokens' => $response->inputTokens,
                    'completion_tokens' => $response->outputTokens,
                    'total_tokens' => $response->totalTokens,
                    'finish_reason' => $response->finishReason,
                    'event' => $eventName,
                    'context' => $this->safeContextMetadata($diagnostics),
                    'runtime' => $trace->toMetadata(),
                    'fallback_error_class' => is_string($response->metadata['fallback_error_class'] ?? null)
                        ? $response->metadata['fallback_error_class']
                        : null,
                ],
                'pending_confirmation' => $pendingConfirmation,
            ];

            if ($toolContext->budgets->webSources !== []) {
                $metadata['web_sources'] = $toolContext->budgets->webSources;
            }

            $assistant = $this->messages->persistOutbound(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::Assistant,
                channel: $inbound?->channel ?? MessageChannel::Telegram,
                messageType: MessageType::Text,
                body: $response->text,
                parentMessageId: $inbound?->id,
                occurredAt: now(),
                metadata: $metadata,
            ))->message;

            if ($inbound !== null) {
                $this->markInbound($inbound, 'completed');
                $this->memoryTurns->afterSuccessfulTurn($user, $conversation, $inbound, $assistant);
            }

            return new ConversationAiTurnResult(assistantMessage: $assistant);
        } catch (Throwable $exception) {
            $trace->finalOutcome = 'unavailable';
            $this->logTrace($trace);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            try {
                Log::warning('AI conversation turn failed', [
                    'provider' => $configuration->provider,
                    'model' => $configuration->model,
                    'configuration' => $configuration->roleKey()->value,
                    'error_class' => $exception::class,
                    'error_message' => $exception->getMessage(),
                    'latency_ms' => $latencyMs,
                ]);
            } catch (Throwable) {
            }

            $errorText = self::AI_FAILURE;

            if ($exception instanceof ChatAttachmentException) {
                $errorText = $exception->getMessage();
            } elseif ($exception instanceof AiConfigurationException && $exception->getMessage() === 'vision_not_supported') {
                $errorText = self::VISION_NOT_SUPPORTED;
            }

            $this->messages->persistSystem(new PersistMessageData(
                conversation: $conversation,
                role: MessageRole::System,
                channel: $inbound?->channel ?? MessageChannel::Telegram,
                messageType: MessageType::System,
                body: $errorText,
                parentMessageId: $inbound?->id,
                occurredAt: now(),
                metadata: [
                    'technical' => true,
                    'ai' => [
                        'configuration' => $configuration->roleKey()->value,
                        'provider' => $configuration->provider,
                        'model' => $configuration->model,
                        'status' => 'failed',
                        'error_class' => $exception::class,
                        'latency_ms' => $latencyMs,
                        'event' => $eventName,
                    ],
                ],
            ));

            if ($inbound !== null) {
                $this->markInbound($inbound, 'failed');
            }

            return new ConversationAiTurnResult(errorText: $errorText);
        }
    }

    /**
     * @param  array<string, mixed>  $diagnostics
     * @return array<string, mixed>
     */
    private function safeContextMetadata(array $diagnostics): array
    {
        return [
            'estimated_input_tokens' => $diagnostics['estimated_input_tokens'] ?? null,
            'output_reserve' => $diagnostics['output_reserve'] ?? null,
            'input_budget' => $diagnostics['input_budget'] ?? null,
            'utilization_percent' => $diagnostics['utilization_percent'] ?? null,
            'overflow_prevented' => (bool) ($diagnostics['overflow_prevented'] ?? false),
            'sources' => $diagnostics['sources'] ?? [],
            'trimmed' => $diagnostics['trimmed'] ?? [],
            'continuity_source' => $diagnostics['continuity_source'] ?? null,
            'topic_mode' => $diagnostics['topic_mode'] ?? null,
            'reference_outcome' => $diagnostics['reference_outcome'] ?? null,
            'clarification_reason' => $diagnostics['clarification_reason'] ?? null,
            'working_context_tokens' => $diagnostics['working_context_tokens'] ?? null,
        ];
    }

    /**
     * @param  list<ToolResult>  $results
     */
    private function fallbackOutcome(string $fallback, array $results): string
    {
        if ($fallback === AiFailureFallback::ANSWER_UNAVAILABLE) {
            return 'unavailable';
        }

        $lower = mb_strtolower($fallback);

        if (str_contains($lower, 'готово') || str_contains($lower, 'напомню')) {
            return 'mutation_fallback';
        }

        return $results === [] ? 'unavailable' : 'partial';
    }

    private function logTrace(AgentTurnTrace $trace): void
    {
        try {
            Log::info('agent_turn', $trace->toLogContext());
        } catch (Throwable) {
        }
    }

    /**
     * @param  list<ToolResult>  $results
     * @return array{id: string, status: string, tool_name: string, summary: string, preview: array<string, mixed>|null, expires_at: string|null}|null
     */
    private function pendingConfirmationFromResults(array $results): ?array
    {
        foreach (array_reverse($results) as $result) {
            if (($result->payload['error'] ?? null) !== 'confirmation_required') {
                continue;
            }

            $id = (string) ($result->payload['confirmation_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $preview = $result->payload['preview'] ?? null;

            return [
                'id' => $id,
                'status' => ToolConfirmationStatus::Pending->value,
                'tool_name' => $result->name,
                'summary' => (string) ($result->payload['summary'] ?? ''),
                'preview' => is_array($preview) ? $preview : null,
                'expires_at' => isset($result->payload['expires_at'])
                    ? (string) $result->payload['expires_at']
                    : null,
            ];
        }

        return null;
    }

    private function inboundHasImages(?Message $inbound): bool
    {
        if ($inbound === null) {
            return false;
        }

        $inbound->loadMissing('attachments');

        foreach ($inbound->attachments as $attachment) {
            if ($attachment->isImage() && ! $attachment->isPurged() && $attachment->storage_path !== '') {
                return true;
            }
        }

        return false;
    }

    private function completeWithoutVision(
        Conversation $conversation,
        Message $inbound,
        AiRoleSetting $configuration,
        float $startedAt,
        ?string $eventName,
    ): ConversationAiTurnResult {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        $assistant = $this->messages->persistOutbound(new PersistMessageData(
            conversation: $conversation,
            role: MessageRole::Assistant,
            channel: $inbound->channel ?? MessageChannel::Telegram,
            messageType: MessageType::Text,
            body: self::VISION_NOT_SUPPORTED,
            parentMessageId: $inbound->id,
            occurredAt: now(),
            metadata: [
                'ai' => [
                    'configuration' => $configuration->roleKey()->value,
                    'provider' => $configuration->provider,
                    'model' => $configuration->model,
                    'latency_ms' => $latencyMs,
                    'event' => $eventName,
                    'error' => 'vision_not_supported',
                ],
            ],
        ))->message;

        $this->markInbound($inbound, 'completed');

        return new ConversationAiTurnResult(assistantMessage: $assistant);
    }

    private function assertReady(AiRoleSetting $configuration): void
    {
        if ($configuration->roleKey() === AiRoleKey::OwnerAnalysis) {
            throw new AiConfigurationException('Owner Analysis AI is not used for personal conversations.');
        }

        if (! $configuration->is_enabled) {
            throw new AiConfigurationException('AI configuration is disabled.');
        }

        if (! filled($configuration->provider) || ! filled($configuration->model)) {
            throw new AiConfigurationException('AI configuration is incomplete.');
        }
    }

    private function existingAssistantReply(Message $inbound): ?Message
    {
        return Message::query()
            ->where('parent_message_id', $inbound->id)
            ->where('role', MessageRole::Assistant)
            ->orderBy('id')
            ->first();
    }

    private function pendingIsFresh(Message $inbound): bool
    {
        $updatedAt = $inbound->updated_at;

        if ($updatedAt === null) {
            return false;
        }

        return $updatedAt->gt(now()->subSeconds(self::PENDING_STALE_SECONDS));
    }

    private function voicePresentationHint(Message $inbound): ?string
    {
        $modality = $inbound->metadata['modality'] ?? null;

        if ($modality !== 'voice' || ! $this->voiceSettings->spokenStyleEnabled()) {
            return null;
        }

        $hint = $this->voiceSettings->spokenStyleHint();

        return $hint !== '' ? $hint : null;
    }

    private function processingStatus(Message $inbound): ?string
    {
        $status = $inbound->metadata['ai']['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function markInbound(Message $inbound, string $status): void
    {
        $metadata = $inbound->metadata ?? [];
        $metadata['ai'] = array_merge($metadata['ai'] ?? [], [
            'status' => $status,
        ]);

        $inbound->forceFill(['metadata' => $metadata])->save();
    }
}
