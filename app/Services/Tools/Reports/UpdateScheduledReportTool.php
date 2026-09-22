<?php

namespace App\Services\Tools\Reports;

use App\Enums\ToolOperationClass;
use App\Services\Ai\DTO\ToolCall;
use App\Services\Ai\DTO\ToolDefinition;
use App\Services\Ai\DTO\ToolResult;
use App\Services\Reports\ScheduledReportException;
use App\Services\Reports\ScheduledReportService;
use App\Services\Tools\JarvisTool;
use App\Services\Tools\ToolExecutionContext;
use App\Services\Tools\ToolMeta;
use App\Services\Users\UserCapability;
use App\Services\Workspace\Presentation\HumanScheduledReportDescription;

final class UpdateScheduledReportTool implements JarvisTool
{
    public const NAME = 'update_scheduled_report';

    public function __construct(
        private readonly ScheduledReportService $reports,
        private readonly ScheduledReportToolResolver $resolver,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function definition(): ToolDefinition
    {
        return new ToolDefinition(
            name: self::NAME,
            description: 'Updates an owned scheduled report. Use add_source to attach calendar/gmail/groups without guessing ids. If several reports could match, ask.',
            parameters: [
                'type' => 'OBJECT',
                'properties' => [
                    'report_id' => ['type' => 'INTEGER'],
                    'query' => ['type' => 'STRING'],
                    'name' => ['type' => 'STRING'],
                    'local_time' => ['type' => 'STRING'],
                    'period_mode' => ['type' => 'STRING'],
                    'sources' => [
                        'type' => 'ARRAY',
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
                    'add_source' => ['type' => 'OBJECT', 'description' => 'Merge one source, e.g. {type: google_calendar, calendar_scope: all_relevant, calendar_names: [Семья]}.'],
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
        $resolved = $this->resolver->resolve($call, $context, $this->name());
        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $attributes = [];
        foreach (['name', 'local_time', 'period_mode', 'sources', 'add_source'] as $key) {
            if (array_key_exists($key, $call->arguments)) {
                $attributes[$key] = $call->arguments[$key];
            }
        }

        try {
            $report = $this->reports->updateOwned($context->user, (int) $resolved['report']->id, $attributes);
        } catch (ScheduledReportException $exception) {
            return ToolResult::failure($call->id, $this->name(), [
                'success' => false,
                'error' => $exception->error,
                'message' => $exception->getMessage(),
                'candidates' => $exception->candidates,
            ]);
        }

        return ToolResult::success($call->id, $this->name(), [
            'success' => true,
            'report_id' => (int) $report->id,
            'description' => HumanScheduledReportDescription::sentence($report),
            'report' => $this->reports->serialize($report),
        ]);
    }
}
