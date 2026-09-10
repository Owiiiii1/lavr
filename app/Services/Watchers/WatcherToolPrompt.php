<?php

namespace App\Services\Watchers;

final class WatcherToolPrompt
{
    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return [
            'create_watcher',
            'list_watchers',
            'get_watcher',
            'update_watcher',
            'pause_watcher',
            'resume_watcher',
            'cancel_watcher',
            'list_watcher_occurrences',
            'run_watcher_now',
        ];
    }

    /**
     * @return list<string>
     */
    public static function lines(): array
    {
        return [
            'Reminders are a known time when the user themselves must act (“напомни мне завтра в 9 проверить почту”). Watchers are a future condition/event (“если завтра всё ещё не готово”, “жди письмо от школы”). Scheduled reports collect sources at a clock time (“каждое утро дай сводку почты”, “каждый вечер планы на завтра”) via create_scheduled_report. Tasks are work items. B.2 proactive suggestions are separate heuristics — do not recreate them as watchers.',
            'create_watcher when the user asked LAVR to watch, check, or report — not when they asked to be reminded to do it themselves. Resolve source to a stable id (task_id, knowledge_entity_id, project_id, thread_id, repository) when the source is specific. If several GitHub repos or entities could match, ask — never guess. For “если эта задача завтра всё ещё будет открыта / still open tomorrow” use trigger_type=task_state, condition_type=status_equals (aliases: still_open, open_tomorrow), condition.status=open, condition.hours=24, and the trusted recent task_id — never overdue_by unless the task has a due date.',
            'Three Gmail intents. Reminder: “Напомни мне в 9 проверить почту.” Scheduled report: “Каждое утро рассказывай, что нового в почте” / “Каждый день в 9 присылай сводку Gmail” / “каждое утро в 9 письма и группы” → create_scheduled_report, not this tool. Event: “Жди письмо от школы.” / “Следи за письмами от @example.com.” → Gmail recurring event watcher, NOT a digest and NOT knowledge_event.',
            'Never hijack a scheduled report into a watcher. Periodic plans, mail digests, and group summaries use create_scheduled_report.',
            'If the user adds another sender (“и от академии тоже”, “и ещё следи за письмами от example.com”) and a trusted recent Gmail event watcher exists, update that watcher. Do not only search Gmail. If several watchers could match, ask. Never guess ids.',
            'You may say Gmail event monitoring is active only when create_watcher succeeded AND payload.kind is gmail_event (trigger_type=gmail_message). Periodic mail/group summaries are create_scheduled_report, not this tool. If create_watcher failed, say the returned message and do not claim monitoring exists. If another non-Gmail watcher succeeded, do not describe it as Gmail monitoring. Do not fall back to knowledge_event, a reminder, or a project watcher for mail.',
            'The same LAVR-checks-vs-user-acts split applies to Calendar and GitHub. “Каждое утро проверь календарь и расскажи, что сегодня” → create_scheduled_report. “Проверяй каждый день GitHub и сообщай о новых commit” → github watcher. “Напомни мне проверить календарь” → reminder.',
            'One-shot is the default for a single reply/deadline on tasks. Recurring is for “каждый раз / следи / жди письма / каждое утро проверяй”. Do not fire on historical inbox/repo/calendar items; the first check only establishes a baseline of already-seen items. Later checks report only what appeared after that cursor. If the user also asked to check whether matching mail is already in the inbox, call search_gmail first, then create the watcher. Never dump Gmail message ids or thread ids to the user.',
            'If create_watcher returns google_not_connected, say that LAVR can do this after Gmail is connected. If it returns gmail_scope_required, say Gmail access must be granted. If it returns gmail_filter_required, ask for a sender or domain. Never say LAVR cannot check Gmail when Gmail tools or watchers are available.',
            'Reactions: notify / create_notification / create_reminder / create_task / run_internal_analysis / propose_action. Never send Gmail, write Calendar, or write GitHub from a watcher. Never mark Gmail messages read, archive, label, or reply. External writes become a proposed action for later confirmation.',
            'list_watchers / get_watcher / list_watcher_occurrences read owned watchers. pause_watcher / resume_watcher / cancel_watcher / update_watcher / run_watcher_now mutate or check one owned watcher. run_watcher_now is a check only and does not bypass confirmation. Never pass user_id or integration_account_id. Foreign watcher ids fail.',
        ];
    }
}
