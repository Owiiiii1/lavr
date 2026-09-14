<?php

namespace App\Services\Tools;

use App\Enums\ToolConfirmationDecision;
use App\Enums\ToolExecutionLogStatus;
use App\Models\IntegrationAccount;
use App\Models\ToolExecutionLog;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Knowledge\KnowledgeToolResultIngestor;
use App\Services\WebResearch\Exceptions\WebResearchException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ToolExecutionService
{
    public function __construct(
        private readonly ToolConfirmationPolicy $policy,
        private readonly IntegrationAccountService $accounts,
        private readonly ToolConfirmationService $confirmations,
        private readonly KnowledgeToolResultIngestor $knowledge = new KnowledgeToolResultIngestor,
    ) {}

    public function run(ToolRegistry $registry, ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $startedAt = microtime(true);
        $tool = $registry->resolve($call->name);
        $meta = $tool?->meta();
        $account = $this->resolveAccount($context, $meta, $call);

        if ($tool === null || ! $tool->isAvailable($context)) {
            $result = ToolResult::failure($call->id, $call->name, [
                'success' => false,
                'error' => 'tool_not_available',
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::Denied,
                ToolConfirmationDecision::Denied,
                $result,
                $startedAt,
            );

            return $result;
        }

        $decision = $this->policy->decide($tool, $context, $call);

        if ($decision === ToolConfirmationDecision::Denied) {
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'tool_denied',
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::Denied,
                $decision,
                $result,
                $startedAt,
            );

            return $result;
        }

        if ($decision === ToolConfirmationDecision::ConfirmationRequired) {
            if (method_exists($tool, 'assertReady')) {
                try {
                    $tool->assertReady($context);
                } catch (IntegrationException $exception) {
                    $result = ToolResult::failure($call->id, $tool->name(), array_merge([
                        'success' => false,
                        'error' => $exception->error,
                        'retryable' => $exception->retryable,
                    ], $exception->context));
                    $this->persistLog(
                        $context,
                        $call,
                        $meta,
                        $account,
                        ToolExecutionLogStatus::Failed,
                        $decision,
                        $result,
                        $startedAt,
                    );

                    return $result;
                }
            }

            $pending = $this->confirmations->createPending(
                $context->user,
                $context->conversation,
                $tool->name(),
                $call->arguments,
                $call->id,
            );

            $preview = $this->confirmations->previewFor($pending);
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'confirmation_required',
                'message' => $meta?->confirmationHint ?? 'This action needs confirmation before it can run.',
                'confirmation_id' => $pending->public_id,
                'summary' => $this->confirmations->summaryFor($pending),
                'preview' => $preview,
                'expires_at' => optional($pending->expires_at)?->toIso8601String(),
            ]);
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                ToolExecutionLogStatus::ConfirmationRequired,
                $decision,
                $result,
                $startedAt,
            );

            return $result;
        }

        try {
            $result = $tool->execute($call, $context);
        } catch (IntegrationException $exception) {
            $result = ToolResult::failure($call->id, $tool->name(), array_merge([
                'success' => false,
                'error' => $exception->error,
                'retryable' => $exception->retryable,
            ], $exception->context));
        } catch (WebResearchException $exception) {
            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => $exception->error,
                'retryable' => $exception->retryable,
            ]);
        } catch (Throwable $exception) {
            try {
                Log::warning('tool execution failed', [
                    'tool' => $tool->name(),
                    'user_id' => $context->user->id,
                    'provider' => $meta?->provider,
                    'error_code' => 'tool_failed',
                ]);
            } catch (Throwable) {
            }

            $result = ToolResult::failure($call->id, $tool->name(), [
                'success' => false,
                'error' => 'tool_failed',
            ]);
        }

        $status = $result->success
            ? ToolExecutionLogStatus::Succeeded
            : ToolExecutionLogStatus::Failed;

        try {
            $this->persistLog(
                $context,
                $call,
                $meta,
                $account,
                $status,
                $decision,
                $result,
                $startedAt,
            );
        } catch (Throwable) {
        }

        if ($account !== null) {
            if ($result->success) {
                $this->accounts->recordSuccess($account);
            } else {
                $this->accounts->recordError($account, (string) ($result->payload['error'] ?? 'tool_failed'));
            }
        }

        if ($result->success) {
            $this->knowledge->ingest($context, $result);
        }

        return $result;
    }

    private function resolveAccount(ToolExecutionContext $context, ?ToolMeta $meta, ToolCall $call): ?IntegrationAccount
    {
        if ($meta?->provider === null) {
            return null;
        }

        $accountId = (int) ($call->arguments['account_id'] ?? $call->arguments['integration_account_id'] ?? 0);

        try {
            if ($accountId > 0) {
                return $this->accounts->getAccount($context->user, $accountId, $meta->provider);
            }

            return $this->accounts->getActiveAccount($context->user, $meta->provider);
        } catch (IntegrationException) {
            return null;
        }
    }

    private function persistLog(
        ToolExecutionContext $context,
        ToolCall $call,
        ?ToolMeta $meta,
        ?IntegrationAccount $account,
        ToolExecutionLogStatus $status,
        ToolConfirmationDecision $decision,
        ToolResult $result,
        float $startedAt,
    ): void {
        $finishedAt = now();
        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        ToolExecutionLog::query()->create([
            'user_id' => $context->user->id,
            'conversation_id' => $context->conversation->id,
            'tool_name' => $call->name,
            'capability' => $meta?->capability,
            'provider' => $meta?->provider,
            'integration_account_id' => $account?->id,
            'status' => $status,
            'confirmation_state' => $decision,
            'duration_ms' => $duration,
            'error_code' => $result->success ? null : (string) ($result->payload['error'] ?? 'tool_failed'),
            'metadata' => $this->safeMetadata($result, $meta),
            'started_at' => now()->subMilliseconds(max(0, $duration)),
            'finished_at' => $finishedAt,
        ]);

        try {
            Log::info('tool executed', [
                'tool' => $call->name,
                'user_id' => $context->user->id,
                'provider' => $meta?->provider,
                'status' => $status->value,
                'duration_ms' => $duration,
                'account_id' => $account?->id,
                'error_code' => $result->success ? null : (string) ($result->payload['error'] ?? 'tool_failed'),
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function safeMetadata(ToolResult $result, ?ToolMeta $meta): array
    {
        $payload = $result->payload;
        $metadata = [];

        if ($meta?->provider !== null) {
            $metadata['provider'] = $meta->provider;
            $metadata['operation'] = $meta->operation->value;
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            $metadata['error'] = $payload['error'];
        }

        foreach (['count', 'result_count', 'groups_searched', 'topics_count', 'memories_count', 'summaries_count', 'calendars_count'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key])) {
                $metadata[$key] = (int) $payload[$key];
            }
        }

        if (isset($payload['truncated'])) {
            $metadata['truncated'] = (bool) $payload['truncated'];
        }

        if (isset($payload['repository']) && is_string($payload['repository']) && $payload['repository'] !== '') {
            $metadata['repository'] = $payload['repository'];
        }

        if (isset($payload['confirmation_id']) && is_string($payload['confirmation_id'])) {
            $metadata['confirmation_id'] = $payload['confirmation_id'];
        }

        foreach (['task_id', 'reminder_id', 'project_id', 'watcher_id', 'report_id', 'scheduled_report_id'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key]) && (int) $payload[$key] > 0) {
                $metadata[$key] = (int) $payload[$key];
            }
        }

        if (isset($payload['calendar_event_id']) && is_string($payload['calendar_event_id']) && $payload['calendar_event_id'] !== '') {
            $metadata['calendar_event_id'] = mb_substr($payload['calendar_event_id'], 0, 80);
        }

        if (isset($payload['file_id']) && is_string($payload['file_id']) && $payload['file_id'] !== '') {
            $metadata['file_id'] = mb_substr($payload['file_id'], 0, 80);
        }

        foreach (['title', 'text', 'name'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && trim($payload[$key]) !== '') {
                $metadata['title'] = mb_substr(trim($payload[$key]), 0, 80);
                break;
            }
        }

        if (isset($payload['tasks']) && is_array($payload['tasks'])) {
            $listed = [];

            foreach (array_slice($payload['tasks'], 0, 5) as $row) {
                if (! is_array($row) || ! isset($row['id'])) {
                    continue;
                }

                $listed[] = [
                    'id' => (int) $row['id'],
                    'title' => mb_substr(trim((string) ($row['title'] ?? 'Task')), 0, 80),
                ];
            }

            if ($listed !== []) {
                $metadata['listed_tasks'] = $listed;
                $metadata['result_count'] = count($payload['tasks']);
            }
        }

        if (isset($payload['groups']) && is_array($payload['groups'])) {
            $metadata['result_count'] = count($payload['groups']);
        }

        if (isset($payload['snippets']) && is_array($payload['snippets'])) {
            $metadata['result_count'] = count($payload['snippets']);
        }

        if (isset($payload['calendars']) && is_array($payload['calendars'])) {
            $metadata['result_count'] = count($payload['calendars']);
        }

        if (isset($payload['events']) && is_array($payload['events'])) {
            $metadata['result_count'] = count($payload['events']);
        }

        if (isset($payload['messages']) && is_array($payload['messages'])) {
            $metadata['result_count'] = count($payload['messages']);
        }

        if (isset($payload['char_count']) && is_numeric($payload['char_count'])) {
            $metadata['char_count'] = (int) $payload['char_count'];
        }

        if (isset($payload['labels']) && is_array($payload['labels'])) {
            $metadata['result_count'] = count($payload['labels']);
        }

        foreach (['repositories', 'commits', 'files', 'issues', 'pull_requests', 'branches', 'workflow_runs', 'comments', 'results'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $metadata['result_count'] = count($payload[$key]);
            }
        }

        return $metadata;
    }
}
