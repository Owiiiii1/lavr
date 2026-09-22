<?php

namespace App\Services\Tools\Reports;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reports\ScheduledReportException;
use App\Services\Reports\ScheduledReportIntent;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Workspace\Presentation\HumanScheduledReportDescription;

final class CreateScheduledReportTool implements JarvisTool
{
    public const NAME = 'create_scheduled_report';

    public function __construct(
        private readonly ScheduledReportService $reports,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Creates a scheduled composite report. Use for “каждый вечер в 22 планы на завтра”, “каждое утро в 8:30 планы на сегодня”, “каждое утро в 9 письма и группы”. Not a reminder and not a watcher. Never invent success: only confirm after this tool returns success=true and report_id.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING'],
                    'report_type' => ['type' => 'STRING', 'description' => 'daily_plan, tomorrow_plan, mail_groups_digest, or custom_composite.'],
                    'period_mode' => ['type' => 'STRING', 'description' => 'today, tomorrow, since_previous_report, last_24h.'],
                    'local_time' => ['type' => 'STRING', 'description' => 'HH:MM in the user timezone.'],
                    'sources' => [
                        'type' => 'ARRAY',
                        'description' => 'Semantic sources: {type: tasks|reminders|synthesis|google_calendar|gmail|telegram_groups}.',
                        'items' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'type' => ['type' => 'STRING', 'description' => 'tasks, reminders, projects, synthesis, google_calendar, gmail, telegram_groups, notifications, or commitments.'],
                                'calendar_scope' => ['type' => 'STRING'],
                                'calendar_names' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                                'mode' => ['type' => 'STRING'],
                            ],
                        ],
                    ],
                ],
            ],
        );
    }

    public function meta(): ToolMeta
    {
        return new ToolMeta(capability: UserCapability::SCHEDULED_REPORTS, operation: ToolOperationClass::Write);
    }

    public function isAvailable(ToolExecutionContext $context): bool
    {
        return $context->user->isActive() && $context->user->canUseCapability(UserCapability::SCHEDULED_REPORTS);
    }

    public function execute(ToolCall $call, ToolExecutionContext $context): ToolResult
    {
        $inbound = trim((string) ($context->inbound?->body ?? ''));
        $draft = $inbound !== '' ? ScheduledReportIntent::fromInbound($inbound, $context->user) : null;
        $input = is_array($draft) ? $draft : [];

        foreach (['name', 'report_type', 'period_mode', 'local_time', 'sources'] as $key) {
            if (array_key_exists($key, $call->arguments) && $call->arguments[$key] !== null && $call->arguments[$key] !== '') {
                $input[$key] = $call->arguments[$key];
            }
        }

        $input['conversation_id'] = $context->conversation->id;
        $input['timezone'] = (string) ($context->user->timezone ?: 'UTC');

        try {
            $report = $this->reports->create($context->user, $input, 'tool');
        } catch (ScheduledReportException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'message' => $exception->getMessage(),
                'kind' => 'failed',
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'report_id' => (int) $report->id,
            'name' => (string) $report->name,
            'status' => $report->status->value,
            'report_type' => $report->report_type->value,
            'period_mode' => $report->period_mode->value,
            'local_time' => (string) $report->local_time,
            'timezone' => (string) $report->timezone,
            'next_run_at' => optional($report->next_run_at)?->toIso8601String(),
            'description' => HumanScheduledReportDescription::sentence($report),
            'sources' => HumanScheduledReportDescription::sourceLabels($report),
            'kind' => 'scheduled_report',
            'confirm_as' => HumanScheduledReportDescription::sentence($report),
        ]);
    }
}
