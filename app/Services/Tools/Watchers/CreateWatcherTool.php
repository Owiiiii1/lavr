<?php

namespace App\Services\Tools\Watchers;

use App\Enums\AutomationIntentKind;
use App\Enums\ToolOperationClass;
use App\Enums\WatcherCreatedBy;
use App\Enums\WatcherStatus;
use App\Enums\WatcherTriggerType;
use App\Models\Watcher;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Automation\AutomationIntentRouter;
use App\Services\ConversationIntelligence\ReferenceResolver;
use App\Services\Integrations\Exceptions\IntegrationException;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Reports\ScheduledReportIntent;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Watchers\Exceptions\WatcherException;
use App\Services\Watchers\GmailEventRequest;
use App\Services\Watchers\GmailWatcherQuery;
use App\Services\Watchers\ProactiveCheckIntent;
use App\Services\Watchers\WatcherSchedule;
use App\Services\Watchers\WatcherService;

final class CreateWatcherTool implements JarvisTool
{
    public const NAME = 'create_watcher';

    public function __construct(
        private readonly WatcherService $watchers,
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleOAuthService $oauth,
        private readonly ReferenceResolver $references = new ReferenceResolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates an explicit LAVR watcher for a future condition or event (not a reminder, not a scheduled report). Reminder: “напомни мне проверить почту”. Scheduled report: “каждое утро дай сводку почты / планы на завтра” → create_scheduled_report. Event: “жди письмо от школы / следи за письмами от @example.com / когда Marco ответит” → gmail_message recurring event watcher. Never use knowledge_event for Gmail. Never invent user_id or integration_account_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING', 'description' => 'Short watcher name.'],
                    'trigger_type' => ['type' => 'STRING', 'description' => 'knowledge_event, task_state, reminder_state, time_condition, calendar_event, gmail_message, github_event.'],
                    'source_type' => ['type' => 'STRING', 'description' => 'knowledge_entity, project, task, reminder, gmail, calendar, github, time.'],
                    'condition_type' => ['type' => 'STRING', 'description' => 'Controlled condition, e.g. thread_received_reply, github_new_commit, overdue_by, entity_event_type, new_item.'],
                    'reaction_type' => ['type' => 'STRING', 'description' => 'notify, create_notification, create_reminder, create_task, run_internal_analysis, propose_action.'],
                    'mode' => ['type' => 'STRING', 'description' => 'one_shot or recurring. Gmail event monitoring (“следи / жди письма”) is recurring unless the user asked only for the first reply.'],
                    'task_id' => ['type' => 'INTEGER', 'description' => 'Owned task id when watching a task/deadline.'],
                    'knowledge_entity_id' => ['type' => 'INTEGER', 'description' => 'Owned knowledge entity id when watching a timeline. Never use this for Gmail monitoring.'],
                    'project_id' => ['type' => 'INTEGER', 'description' => 'Owned project id when scoped.'],
                    'entity_name' => ['type' => 'STRING', 'description' => 'Fallback name/alias to resolve a knowledge entity, e.g. YFS.'],
                    'cooldown_seconds' => ['type' => 'INTEGER', 'description' => 'Minimum seconds between notifications.'],
                    'source' => ['type' => 'OBJECT', 'description' => 'Filters: sender, senders, sender_domain, sender_domains, subject, query, thread_id. For a Gmail digest set digest=true, query=in:inbox, schedule.kind=daily_local. For event monitoring pass sender_domains such as ["example.com"]. Do not pass integration_account_id or user_id.'],
                    'condition' => ['type' => 'OBJECT', 'description' => 'Bounded condition config: hours, status, event_type, sender, subject. For still-open-tomorrow set status=open and hours=24.'],
                    'reaction_config' => ['type' => 'OBJECT', 'description' => 'Bounded reaction config. For propose_action include tool name only — never execute it.'],
                ],
                'required' => [],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::WATCHERS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::WATCHERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $inbound = trim((string) ($context->inbound?->body ?? ''));
        if ($inbound !== '') {
            $kind = (new AutomationIntentRouter)->classify($inbound);
            if ($kind === AutomationIntentKind::Clarify) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => 'clarify_intent',
                    'message' => 'Уточніть: потрібна щоденна сводка, чи повідомлення лише коли виконається умова?',
                    'kind' => 'failed',
                ]);
            }
            if ($kind === AutomationIntentKind::ScheduledReport) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => 'use_scheduled_report',
                    'message' => 'This request is a scheduled report. Call create_scheduled_report.',
                    'kind' => 'failed',
                ]);
            }
            if ($kind === AutomationIntentKind::Reminder) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => 'use_reminder',
                    'message' => 'This request is a reminder. Call create_reminder.',
                    'kind' => 'failed',
                ]);
            }
        }

        try {
            $input = $this->normalizeInput($call, $context);
            $this->assertNotWrongGmailFallback($input, $context);
            $this->assertGmailReady($input, $context);

            $inbound = trim((string) ($context->inbound?->body ?? ''));
            if (ProactiveCheckIntent::isGmailFilterAddon($inbound) || ProactiveCheckIntent::isGmailEventMonitoring($inbound)) {
                $refined = $this->refineExistingGmailEvent($input, $context);
                if ($refined !== null) {
                    return $this->successPayload($call->id, $refined, (string) ($context->user->timezone ?: 'UTC'), updated: true);
                }
            }

            $input['conversation_id'] = $context->conversation->id;
            $watcher = $this->watchers->create($context->user, $input, WatcherCreatedBy::Tool);
        } catch (WatcherException $exception) {
            $payload = [
                'success' => false,
                'error' => $exception->error,
                'message' => $this->userMessage($exception),
                'kind' => 'failed',
            ];

            if ($exception->candidates !== []) {
                $payload['candidates'] = $exception->candidates;
            }

            return ToolResult::failure($call->id, $this->name(), $payload);
        }

        return $this->successPayload($call->id, $watcher, (string) ($context->user->timezone ?: 'UTC'));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeInput(ToolCall $call, ToolExecutionContext $context): array
    {
        $input = $call->arguments;
        unset($input['user_id'], $input['integration_account_id']);
        if (is_array($input['source'] ?? null)) {
            unset($input['source']['user_id'], $input['source']['integration_account_id']);
        }

        $inbound = trim((string) ($context->inbound?->body ?? ''));
        if (ScheduledReportIntent::matches($inbound) || ProactiveCheckIntent::jarvisShouldMonitorMail($inbound)) {
            throw new WatcherException('use_scheduled_report', 'This request is a scheduled report. Call create_scheduled_report.');
        }

        $event = GmailEventRequest::fromInbound($inbound, $context->user, $input);
        $watchingMail = ProactiveCheckIntent::isGmailEventMonitoring($inbound)
            || ProactiveCheckIntent::isGmailFilterAddon($inbound)
            || preg_match('/(?:жди|следи|watch|notify).{0,48}(?:письм|mail|email)/u', mb_strtolower($inbound)) === 1;
        if ($event !== null && $watchingMail) {
            if (! GmailWatcherQuery::hasFilter(is_array($event['source'] ?? null) ? $event['source'] : [])) {
                throw new WatcherException('gmail_filter_required', 'Gmail event watchers need a sender or domain.');
            }

            return $event;
        }

        $taskId = $this->resolveTaskId($call, $context);

        if ($taskId !== null) {
            $input['task_id'] = $taskId;
        } else {
            unset($input['task_id']);
        }

        $inboundLower = mb_strtolower($inbound);
        $condition = mb_strtolower(trim((string) ($input['condition_type'] ?? '')));

        if ($condition === '' && $inboundLower !== '' && preg_match('/открыт|still open|останет/u', $inboundLower) === 1) {
            $input['condition_type'] = 'still_open';
            $condition = 'still_open';
        }

        if (! isset($input['trigger_type']) || $input['trigger_type'] === '') {
            if (isset($input['task_id'])) {
                $input['trigger_type'] = 'task_state';
            }
        }

        if (in_array($condition, ['still_open', 'remains_open', 'still_open_tomorrow', 'open_tomorrow', 'if_open', 'status_equals', 'status'], true)) {
            $conditionConfig = is_array($input['condition'] ?? null) ? $input['condition'] : [];
            $conditionConfig['status'] = $conditionConfig['status'] ?? $conditionConfig['expected'] ?? 'open';

            if (! isset($conditionConfig['hours']) && preg_match('/завтра|tomorrow/u', $inboundLower) === 1) {
                $conditionConfig['hours'] = 24;
            }

            $input['condition'] = $conditionConfig;
            $input['condition_type'] = 'status_equals';
            $input['mode'] = $input['mode'] ?? 'one_shot';
            $input['reaction_type'] = $input['reaction_type'] ?? 'notify';
        }

        if (($input['name'] ?? '') === '' && isset($input['task_id'])) {
            $input['name'] = 'Если задача останется открытой';
        }

        if ($event !== null && mb_strtolower(trim((string) ($input['trigger_type'] ?? ''))) === 'gmail_message') {
            return $event;
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertNotWrongGmailFallback(array $input, ToolExecutionContext $context): void
    {
        $inbound = trim((string) ($context->inbound?->body ?? ''));
        if ($inbound === '' || (! ProactiveCheckIntent::isGmailEventMonitoring($inbound) && ! ProactiveCheckIntent::jarvisShouldMonitorMail($inbound))) {
            return;
        }

        $trigger = mb_strtolower(trim((string) ($input['trigger_type'] ?? '')));
        if ($trigger !== '' && $trigger !== 'gmail_message') {
            throw new WatcherException('gmail_filter_required', 'Gmail monitoring cannot use a non-Gmail watcher.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function refineExistingGmailEvent(array $input, ToolExecutionContext $context): ?Watcher
    {
        $inbound = trim((string) ($context->inbound?->body ?? ''));
        if (! ProactiveCheckIntent::isGmailFilterAddon($inbound)) {
            return null;
        }

        $existing = $this->resolveGmailEventWatcher($context);
        $source = is_array($input['source'] ?? null) ? $input['source'] : [];
        if (! GmailWatcherQuery::hasFilter($source)) {
            throw new WatcherException('gmail_filter_required', 'Gmail event watchers need a sender or domain.');
        }

        if ($existing === null) {
            return null;
        }

        return $this->watchers->updateOwned($context->user, (int) $existing->id, [
            'source' => $source,
            'name' => GmailWatcherQuery::displayName(GmailWatcherQuery::merge(
                is_array($existing->source_config) ? $existing->source_config : [],
                $source,
            )),
        ]);
    }

    private function resolveGmailEventWatcher(ToolExecutionContext $context): ?Watcher
    {
        $candidates = [];

        foreach ($context->working?->recentToolReferences ?? [] as $entity) {
            if ($entity->type !== 'watcher' || $entity->id === null || ! $entity->trusted || $entity->expired) {
                continue;
            }

            $watcher = Watcher::query()
                ->where('user_id', $context->user->id)
                ->whereKey($entity->id)
                ->first();
            if ($watcher !== null && GmailWatcherQuery::isEventWatcher($watcher) && $watcher->status === WatcherStatus::Active) {
                $candidates[(int) $watcher->id] = $watcher;
            }
        }

        if (count($candidates) > 1) {
            throw new WatcherException('ambiguous', 'Several Gmail watchers could match.');
        }
        if (count($candidates) === 1) {
            return array_values($candidates)[0];
        }

        $active = Watcher::query()
            ->where('user_id', $context->user->id)
            ->where('status', WatcherStatus::Active)
            ->where('trigger_type', WatcherTriggerType::GmailMessage)
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->filter(static fn (Watcher $watcher): bool => GmailWatcherQuery::isEventWatcher($watcher) && ! WatcherSchedule::isDigest($watcher));

        if ($active->count() > 1) {
            throw new WatcherException('ambiguous', 'Several Gmail watchers could match.');
        }

        return $active->first();
    }

    private function resolveTaskId(ToolCall $call, ToolExecutionContext $context): ?int
    {
        $explicit = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : 0;
        $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $pronominal = $inbound !== '' && $this->references->hasDeictic($inbound);

        if ($explicit > 0) {
            if ($pronominal && $context->working !== null && ! $context->working->trustsTaskId($explicit)) {
                return $context->working->referredTask()?->id;
            }

            return $explicit;
        }

        if (! $pronominal || $context->working === null || ! $context->working->allowsTrustedMutation()) {
            return null;
        }

        $trusted = $context->working->referredTask();

        return $trusted?->id;
    }

    private function successPayload(string $callId, Watcher $watcher, string $timezone, bool $updated = false): ToolResult
    {
        $fresh = $watcher->fresh(['task', 'project', 'knowledgeEntity', 'reminder']) ?? $watcher;
        $serialized = $this->watchers->serialize($fresh, $timezone !== '' ? $timezone : 'UTC');
        $kind = 'other';
        if ($fresh->trigger_type === WatcherTriggerType::GmailMessage) {
            $kind = WatcherSchedule::isDigest($fresh) ? 'gmail_digest' : 'gmail_event';
        }

        return ToolResult::success($callId, $this->name(), [
            'success' => true,
            'watcher_id' => (int) $fresh->id,
            'task_id' => $fresh->task_id !== null ? (int) $fresh->task_id : null,
            'status' => $fresh->status->value,
            'mode' => $fresh->mode->value,
            'name' => $fresh->name,
            'description' => $serialized['description'] ?? null,
            'trigger_type' => $fresh->trigger_type->value,
            'kind' => $kind,
            'updated' => $updated,
            'confirm_as' => (string) ($serialized['description'] ?? ''),
        ]);
    }

    private function userMessage(WatcherException $exception): string
    {
        return match ($exception->error) {
            'use_scheduled_report' => 'Це періодична сводка. Потрібен scheduled report, а не watcher.',
            'use_reminder' => 'Це нагадування, не watcher.',
            'clarify_intent' => 'Уточніть: потрібна щоденна сводка, чи повідомлення лише коли виконається умова?',
            'gmail_filter_required' => 'Не удалось создать мониторинг Gmail: нужен отправитель или домен.',
            'invalid_config' => str_contains(mb_strtolower($exception->getMessage()), 'gmail') || str_contains(mb_strtolower($exception->getMessage()), 'sender')
                ? 'Не удалось создать мониторинг Gmail: нужен отправитель или домен.'
                : 'Не получилось поставить автоматизацию: не хватает задачи или условия. Если речь о конкретной задаче, назовите её или уточните, о какой из недавних.',
            'not_found' => 'Не нашёл задачу, за которой нужно следить.',
            'ambiguous' => str_contains(mb_strtolower($exception->getMessage()), 'gmail')
                ? 'Уточните, какую почтовую автоматизацию дополнить — сейчас их несколько.'
                : 'Уточните, о какой задаче речь — сейчас их несколько.',
            'invalid_name' => 'Нужно короткое название для автоматизации.',
            'google_not_connected' => 'Могу это делать, но сначала нужно подключить Gmail.',
            'gmail_scope_required' => 'Нужно разрешить доступ к Gmail.',
            'capability_denied' => 'Эта автоматизация сейчас недоступна.',
            default => 'Не получилось создать автоматизацию.',
        };
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertGmailReady(array $input, ToolExecutionContext $context): void
    {
        $trigger = mb_strtolower(trim((string) ($input['trigger_type'] ?? '')));
        $source = is_array($input['source'] ?? null) ? $input['source'] : [];
        $needsGmail = $trigger === 'gmail_message' || ($source['digest'] ?? false) === true;
        if (! $needsGmail) {
            return;
        }

        try {
            $account = $this->accounts->getActiveAccount($context->user, 'google');
        } catch (IntegrationException $exception) {
            if ($exception->error === 'forbidden') {
                throw new WatcherException('capability_denied', 'Gmail watchers are not available.');
            }

            throw new WatcherException($exception->error, $exception->getMessage());
        }

        if ($account === null) {
            throw new WatcherException('google_not_connected', 'Gmail is not connected.');
        }

        $scopes = is_array($account->scopes) ? $account->scopes : [];
        if (! $this->oauth->hasGmailReadScope($scopes)) {
            throw new WatcherException('gmail_scope_required', 'Gmail permission is required.');
        }
    }
}
