<?php

namespace App\Services\Users;

final class UserCapability
{
    public const CHAT = 'chat';

    public const MEMORY = 'memory';

    public const KNOWLEDGE = 'knowledge';

    public const TELEGRAM_DM = 'telegram_dm';

    public const REMINDERS = 'reminders';

    public const TASKS = 'tasks';

    public const WATCHERS = 'watchers';

    public const SCHEDULED_REPORTS = 'scheduled_reports';

    public const NOTIFICATIONS = 'notifications';

    public const CABINET = 'cabinet';

    public const PERSONAL_WORKSPACE = 'personal_workspace';

    public const PROFILE = 'profile';

    public const ADMIN = 'admin';

    public const USERS_ADMIN = 'users_admin';

    public const INTEGRATIONS_ADMIN = 'integrations_admin';

    public const TELEGRAM_GROUPS = 'telegram_groups';

    public const GROUP_ANALYSIS = 'group_analysis';

    public const PROJECTS = 'projects';

    public const PEOPLE = 'people';

    public const MEETINGS = 'meetings';

    public const GMAIL = 'gmail';

    public const GOOGLE_CALENDAR = 'google_calendar';

    public const GITHUB = 'github';

    public const STORAGE = 'storage';

    public const WEB_RESEARCH = 'web_research';

    public const VOICE = 'voice';

    public const IMPERSONATION = 'impersonation';

    public const SYSTEM_AI_SETTINGS = 'system_ai_settings';

    /**
     * @return list<string>
     */
    public static function forRegularUser(): array
    {
        return [
            self::CHAT,
            self::MEMORY,
            self::KNOWLEDGE,
            self::TELEGRAM_DM,
            self::REMINDERS,
            self::TASKS,
            self::WATCHERS,
            self::SCHEDULED_REPORTS,
            self::NOTIFICATIONS,
            self::CABINET,
            self::PERSONAL_WORKSPACE,
            self::PROFILE,
            self::WEB_RESEARCH,
            self::VOICE,
            self::STORAGE,
        ];
    }
}
