<?php

namespace App\Services\Reminders;

use App\Services\Tools\CancelReminderTool;
use App\Services\Tools\CompleteReminderTool;
use App\Services\Tools\CreateReminderTool;
use App\Services\Tools\ListRemindersTool;
use App\Services\Tools\SnoozeReminderTool;
use App\Services\Tools\UpdateReminderTool;

final class ReminderToolPrompt
{
    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'create_reminder creates a LAVR Core reminder only when the user themselves must do something at a known time (“напомни мне проверить почту утром”, “remind me to check Gmail”). Telegram and Web Push are optional independent delivery adapters, not a create requirement.',
            'If LAVR must perform the check (“жди письмо от школы”, “следи за письмами от example.com”), use create_watcher. If LAVR must send a scheduled multi-source report (“каждое утро дай сводку почты”, “каждый вечер в 22 планы на завтра”), use create_scheduled_report, never create_reminder or create_watcher. Reminder = the user acts. Watcher = LAVR watches a condition. Scheduled report = LAVR collects sources at a clock time.',
            'The same split applies to Calendar and GitHub: “напомни мне проверить календарь” is a reminder; “каждое утро проверь календарь и расскажи, что сегодня” is a watcher. “Проверяй каждый день GitHub и сообщай о новых commit” is a watcher.',
            'If the notification depends on a future state or event (“если завтра всё ещё не готово”, “когда Apple ответит”), use a watcher instead of create_reminder.',
            'Only call create_reminder when the current user message is itself a reminder request. Follow-ups such as "ты тут?" are not reminder requests.',
            'If the day is known but the clock time is missing, ask "Во сколько напомнить?" and do not call the tool. Do not invent 09:00 or another default time.',
            'Dayparts such as "tomorrow morning" without a clock time are not exact — ask. This ask-for-time rule applies only to reminders, not to “каждое утро проверяй почту” (that is a watcher; default 08:00 local is allowed).',
            'Recurrence values: daily, weekdays, weekly, monthly. Use create_reminder with recurrence when the user asks to be reminded every weekday at 8, every day, weekly, or monthly.',
            'list_reminders lists open reminders. Call it when the user refers to a reminder without a unique identity.',
            'update_reminder changes text, time, timezone, or recurrence. snooze_reminder delays it (10m, 1h, tomorrow, custom). complete_reminder marks it done by the user. cancel_reminder stops it. Done and cancel are different.',
            'If several reminders could match ("перенеси напоминание на завтра"), call list_reminders and ask which one. Never update a random reminder. Pass reminder_id when known.',
            'After a successful create_reminder, confirm in natural language using the returned local time. If telegram_connected is true, mention Telegram. If web_push_available is true, mention browser notifications. If delivery is none, say it is saved in LAVR and visible in the Web reminders panel. Do not say reminders require Telegram. Do not mention tool names.',
        ];
    }

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            CreateReminderTool::NAME,
            ListRemindersTool::NAME,
            UpdateReminderTool::NAME,
            SnoozeReminderTool::NAME,
            CompleteReminderTool::NAME,
            CancelReminderTool::NAME,
        ];
    }
}
