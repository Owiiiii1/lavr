<?php

namespace App\Services\Reports;

final class ScheduledReportToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'create_scheduled_report',
            'list_scheduled_reports',
            'get_scheduled_report',
            'update_scheduled_report',
            'pause_scheduled_report',
            'resume_scheduled_report',
            'cancel_scheduled_report',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Scheduled reports are not reminders and not watchers. Reminder: the user acts at a known time (“напомни в 9 проверить почту”). Watcher: a future condition/event (“жди письмо от школы”, “следи за задачей”). Scheduled report: at a known time LAVR collects sources and delivers one coherent report (“каждый вечер в 22 дай планы на завтра”, “каждое утро в 8:30 планы на сегодня”, “каждое утро в 9 сводка писем и групп”).',
            'create_scheduled_report for periodic reports. Pass report_type daily_plan | tomorrow_plan | mail_groups_digest | custom_composite, local_time HH:MM, period_mode today | tomorrow | since_previous_report, and sources as semantic objects: {type: tasks|reminders|synthesis|google_calendar|gmail|telegram_groups}. For calendars use calendar_scope=all_relevant (primary + Семья/shared). Never pass user_id, raw Gmail queries, or watcher configs.',
            'You may say a report is configured ONLY when create_scheduled_report succeeded (success=true and report_id). If it failed, say the returned message. If the user asked for several reports and only some succeeded, say exactly which ones exist.',
            '“каждое утро дай сводку почты” is a scheduled report, not a Gmail digest watcher. Gmail event monitoring (“жди письмо от школы”) stays create_watcher. “напомни мне проверить почту” stays create_reminder.',
            'Follow-ups (“добавь семейный календарь”) update the unique trusted recent report via update_scheduled_report. If several morning reports could match, ask. Never guess report ids.',
            'list_scheduled_reports / get_scheduled_report read owned reports. pause/resume/cancel/update mutate one owned report. After success, confirm in natural language with time, period, and sources. Do not mention tool names.',
        ];
    }
}
