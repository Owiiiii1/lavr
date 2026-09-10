<?php

namespace App\Services\Tools;

use App\Enums\ReminderStatus;
use App\Enums\ToolOperationClass;
use App\Models\Reminder;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\ConversationIntelligence\ReferenceResolver;
use App\Services\Reminders\ReminderException;
use App\Services\Reminders\ReminderRecurrenceCalculator;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportIntent;
use App\Services\Tools\Watchers\CreateWatcherTool;
use App\Services\Users\UserCapability;
use App\Services\Watchers\ProactiveCheckIntent;

final class CreateReminderTool implements JarvisTool
{
    public const NAME = 'create_reminder';

    public function __construct(
        private readonly ReminderService $reminders,
        private readonly ?CreateWatcherTool $watchers = null,
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
            description: 'Создаёт персональное напоминание, когда пользователь сам должен что-то сделать в известное время («напомни мне проверить почту»), без Telegram как обязательного условия. Если LAVR должен сам прислать отчёт в известное время — create_scheduled_report. Если LAVR должен следить за событием («жди письмо») — create_watcher. Telegram и Web Push — независимые каналы доставки. Поддерживает recurrence: daily, weekdays, weekly, monthly.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'text' => [
                        'type' => 'STRING',
                        'description' => 'What to remind the user about, without the time phrase.',
                    ],
                    'task_id' => [
                        'type' => 'INTEGER',
                        'description' => 'Optional owned task id to link. Use a trusted recent task when the user refers to it with a pronoun.',
                    ],
                    'run_at_local' => [
                        'type' => 'STRING',
                        'description' => 'ISO 8601 datetime with offset for the reminder instant, e.g. 2026-09-04T11:00:00+02:00.',
                    ],
                    'timezone' => [
                        'type' => 'STRING',
                        'description' => 'IANA timezone of the user, e.g. Europe/Rome.',
                    ],
                    'original_time_expression' => [
                        'type' => 'STRING',
                        'description' => 'Optional original time phrase from the user.',
                    ],
                    'recurrence' => [
                        'type' => 'STRING',
                        'description' => 'Optional recurrence: daily, weekdays, weekly, or monthly. Omit for a one-time reminder.',
                    ],
                ],
                'required' => ['text', 'run_at_local'],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(
            capability: UserCapability::REMINDERS,
            operation: ToolOperationClass::Write,
        );
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive()
            && $context->user->canUseCapability(UserCapability::REMINDERS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $inboundText = trim((string) ($context->inbound?->body ?? ''));
        if ($inboundText !== '' && (
            ScheduledReportIntent::matches($inboundText)
            || ProactiveCheckIntent::jarvisShouldMonitorMail($inboundText)
        )) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'use_scheduled_report',
                'message' => 'This request is a scheduled report. Call create_scheduled_report.',
                'kind' => 'failed',
            ]);
        }
        if ($inboundText !== ''
            && $this->watchers !== null
            && $context->user->canUseCapability(UserCapability::GMAIL)
            && ! ProactiveCheckIntent::userSelfReminder($inboundText)
            && ProactiveCheckIntent::isGmailEventMonitoring($inboundText)) {
            return $this->watchers->execute($call, $context);
        }

        $text = trim((string) ($call->arguments['text'] ?? ''));
        $runAtLocal = trim((string) ($call->arguments['run_at_local'] ?? ''));
        $timezone = (string) $context->user->timezone;
        $recurrence = isset($call->arguments['recurrence'])
            ? trim((string) $call->arguments['recurrence'])
            : (isset($call->arguments['recurrence_rule']) ? trim((string) $call->arguments['recurrence_rule']) : null);

        if ($text === '' || $runAtLocal === '') {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_arguments',
            ]);
        }

        if ($recurrence !== null && $recurrence !== '' && ReminderRecurrenceCalculator::parse($recurrence) === null) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => 'invalid_recurrence',
            ]);
        }

        if ($context->inbound !== null) {
            $existing = Reminder::query()
                ->where('user_id', $context->user->id)
                ->where('source_message_id', $context->inbound->id)
                ->whereIn('status', [ReminderStatus::Scheduled, ReminderStatus::Processing])
                ->orderBy('id')
                ->first();

            if ($existing !== null) {
                $local = $existing->run_at->setTimezone($timezone);

                return ToolResult::success($call->id, $this->name(), $this->successPayload(
                    $existing,
                    $local->format('Y-m-d\TH:i:sP'),
                    $timezone,
                    $this->reminders->telegramIsLinked($context->user),
                    true,
                ));
            }
        }

        try {
            $explicitTaskId = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : 0;
            $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));

            if ($explicitTaskId > 0 && $inbound !== '' && $this->references->hasDeictic($inbound) && $context->working !== null && ! $context->working->trustsTaskId($explicitTaskId)) {
                return ToolResult::failure($call->id, $this->name(), [
                    'success' => false,
                    'error' => 'ambiguous',
                    'message' => 'Do not invent a task id. Use a trusted recent task.',
                ]);
            }

            $runAtUtc = $this->reminders->localWallTimeToUtc($runAtLocal, $timezone);
            $local = $runAtUtc->setTimezone($timezone);

            $reminder = $this->reminders->create(
                user: $context->user,
                text: $text,
                runAt: $runAtUtc,
                timezone: $timezone,
                conversation: $context->conversation,
                sourceMessage: $context->inbound,
                recurrence: $recurrence !== '' ? $recurrence : null,
                taskId: $this->linkedTaskId($call, $context),
            );

            return ToolResult::success($call->id, $this->name(), $this->successPayload(
                $reminder,
                $local->format('Y-m-d\TH:i:sP'),
                $timezone,
                $this->reminders->telegramIsLinked($context->user),
            ));
        } catch (ReminderException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function successPayload(
        Reminder $reminder,
        string $runAtLocal,
        string $timezone,
        bool $telegramLinked,
        bool $existing = false,
        bool $webPushAvailable = false,
    ): array {
        return [
            'success' => true,
            'reminder_id' => $reminder->id,
            'text' => $reminder->text,
            'run_at_local' => $runAtLocal,
            'timezone' => $timezone,
            'recurrence' => $reminder->recurrence_rule,
            'telegram_connected' => $telegramLinked,
            'web_push_available' => $webPushAvailable,
            'delivery' => $this->deliveryLabel($telegramLinked, $webPushAvailable),
            'existing' => $existing,
            'task_id' => $reminder->task_id !== null ? (int) $reminder->task_id : null,
        ];
    }

    private function deliveryLabel(bool $telegramLinked, bool $webPushAvailable): string
    {
        if ($telegramLinked && $webPushAvailable) {
            return 'both';
        }

        if ($telegramLinked) {
            return 'telegram';
        }

        if ($webPushAvailable) {
            return 'web_push';
        }

        return 'none';
    }

    private function linkedTaskId(ToolCall $call, ToolExecutionContext $context): ?int
    {
        $explicit = isset($call->arguments['task_id']) ? (int) $call->arguments['task_id'] : 0;
        $inbound = mb_strtolower(trim((string) ($context->inbound?->body ?? '')));
        $pronominal = $inbound !== '' && $this->references->hasDeictic($inbound);

        if ($explicit > 0) {
            if ($pronominal && $context->working !== null && ! $context->working->trustsTaskId($explicit)) {
                return null;
            }

            return $explicit;
        }

        if (! $pronominal || $context->working === null || ! $context->working->allowsTrustedMutation()) {
            return null;
        }

        $trusted = $context->working->uniqueTrustedTask();

        return $trusted?->id;
    }
}
