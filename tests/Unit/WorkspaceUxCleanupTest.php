<?php

namespace Tests\Unit;

use Tests\TestCase;

class WorkspaceUxCleanupTest extends TestCase
{
    public function test_ai_settings_keep_save_labels_visible_and_explain_disabled_roles(): void
    {
        $panel = (string) file_get_contents(base_path('resources/js/Pages/Settings/AiPanel.jsx'));

        $this->assertStringContainsString("saveRole: 'Сохранить конфигурацию'", $panel);
        $this->assertStringContainsString("saveRole: 'Зберегти конфігурацію'", $panel);
        $this->assertStringContainsString('aria-label={t.saveRole}', $panel);
        $this->assertStringContainsString('<span className="whitespace-nowrap">{t.saveRole}</span>', $panel);
        $this->assertStringContainsString('{t.disabledNotice}', $panel);
    }

    public function test_settings_use_one_flat_tab_per_element_with_status_overview(): void
    {
        $index = (string) file_get_contents(base_path('resources/js/Pages/Settings/Index.jsx'));
        $copy = (string) file_get_contents(base_path('resources/js/Pages/Settings/settingsCopy.js'));
        $overview = (string) file_get_contents(base_path('resources/js/Pages/Settings/OverviewPanel.jsx'));

        $this->assertStringContainsString(
            "export const SETTINGS_TABS = ['overview', 'ai', 'telegram', 'google', 'zoom', 'voice', 'web-research', 'activity'];",
            $copy,
        );
        $this->assertStringContainsString("open: 'Открыть'", $copy);
        $this->assertStringContainsString("open: 'Відкрити'", $copy);
        $this->assertStringContainsString("tabActivity: 'Журнал'", $copy);

        $this->assertStringContainsString('deriveSettingsStatus', $index);
        $this->assertStringContainsString('dotClass(statuses[id]?.state)', $index);
        $this->assertStringContainsString('<GooglePanel t={t} status={statuses.google} />', $index);
        $this->assertStringContainsString('<ZoomPanel t={t} status={statuses.zoom} />', $index);
        $this->assertFileDoesNotExist(base_path('resources/js/Pages/Settings/IntegrationsPanel.jsx'));
        $this->assertFileDoesNotExist(base_path('resources/js/Pages/Settings/GeneralPanel.jsx'));

        $this->assertStringContainsString('SettingsStatusCard', $overview);
        $this->assertStringContainsString('CONNECTION_ELEMENTS', $overview);
    }

    public function test_successful_chat_turn_triggers_productivity_refresh_without_reload_or_polling(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));

        $this->assertStringContainsString('refreshProductivity', $workspace);
        $this->assertStringContainsString('workspace.status', $workspace);
        $this->assertStringContainsString('refreshProductivity(payload)', $workspace);
        $this->assertStringContainsString('productivityRefreshToken', $workspace);
        $this->assertStringNotContainsString('window.location.reload', $workspace);
        $this->assertStringNotContainsString('setInterval(', $workspace);
        $this->assertStringNotContainsString('EventSource', $workspace);
        $this->assertStringNotContainsString('new WebSocket', $workspace);
    }

    public function test_panels_reload_when_open_via_refresh_token(): void
    {
        $tasks = (string) file_get_contents(base_path('resources/js/personal-workspace/TasksPanel.jsx'));
        $reminders = (string) file_get_contents(base_path('resources/js/personal-workspace/RemindersPanel.jsx'));
        $inbox = (string) file_get_contents(base_path('resources/js/personal-workspace/NotificationsPanel.jsx'));

        $this->assertStringContainsString('refreshToken = 0', $tasks);
        $this->assertStringContainsString('[open, surface, refreshToken]', $tasks);
        $this->assertStringContainsString('refreshToken = 0', $reminders);
        $this->assertStringContainsString('refreshToken', $reminders);
        $this->assertStringContainsString('refreshToken = 0', $inbox);
        $this->assertStringContainsString('unreadOnly, refreshToken', $inbox);

        $watchers = (string) file_get_contents(base_path('resources/js/personal-workspace/WatchersPanel.jsx'));
        $this->assertStringContainsString('refreshToken = 0', $watchers);
        $this->assertStringContainsString('[open, surface, refreshToken]', $watchers);
    }

    public function test_memory_and_integrations_live_in_settings_not_main_workspace(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $settings = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/WorkspaceSettings.jsx'));
        $memory = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/MemorySettings.jsx'));
        $integrations = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/IntegrationsSettings.jsx'));
        $productivity = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/ProductivitySettings.jsx'));
        $voice = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/VoiceSettings.jsx'));

        $this->assertStringNotContainsString('Last analysis:', $workspace);
        $this->assertStringNotContainsString('<Plug', $workspace);
        $this->assertStringNotContainsString('Integrations', $workspace);
        $this->assertStringContainsString("id: 'memory'", $settings);
        $this->assertStringContainsString("id: 'knowledge'", $settings);
        $this->assertStringContainsString("id: 'integrations'", $settings);
        $this->assertStringContainsString('Память', $memory);
        $this->assertStringContainsString('capabilities.integrations ? integrations : []', $integrations);
        $this->assertStringContainsString('Daily Brief', $productivity);
        $this->assertStringContainsString('Голос ассистента', $voice);
        $this->assertStringContainsString('md:flex md:w-56', $settings);
        $this->assertStringContainsString('mobileDetail', $settings);
        $this->assertStringContainsString("aria-label={t('chat.settings')}", $workspace);
        $this->assertStringContainsString(
            "settings: 'Настройки'",
            (string) file_get_contents(base_path('resources/js/locales/ru.js')),
        );
    }

    public function test_settings_query_is_allowlisted(): void
    {
        $sections = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/sections.js'));
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));

        $this->assertStringContainsString("'memory'", $sections);
        $this->assertStringContainsString("'knowledge'", $sections);
        $this->assertStringContainsString("'integrations'", $sections);
        $this->assertStringContainsString('allowedSettingsSection', $workspace);
        $this->assertStringContainsString('conversationItems', $workspace);
        $this->assertStringNotContainsString('history.replaceState', $sections);
        $this->assertStringNotContainsString('history.replaceState', $workspace);
        $this->assertStringNotContainsString('writeSettingsQuery', $workspace);
    }

    public function test_sidebar_chat_list_survives_settings_open_and_remounts(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));

        $this->assertStringContainsString('let lastKnownConversations = [];', $workspace);
        $this->assertStringContainsString('conversations.length > 0 ? conversations : lastKnownConversations', $workspace);
        $this->assertStringContainsString('lastKnownConversations = conversations;', $workspace);
        $this->assertStringContainsString('return conversationItems;', $workspace);
        $this->assertStringNotContainsString('return conversations;', $workspace);
    }

    public function test_settings_password_fields_do_not_capture_sidebar_search_autofill(): void
    {
        $workspace = (string) file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $profile = (string) file_get_contents(base_path('resources/js/personal-workspace/settings/ProfileSettings.jsx'));

        $this->assertStringContainsString('name="chat-search"', $workspace);
        $this->assertStringContainsString('autoComplete="off"', $workspace);
        $this->assertStringContainsString('data-lpignore="true"', $workspace);
        $this->assertStringContainsString('autoComplete="username"', $profile);
        $this->assertStringContainsString('autoComplete="current-password"', $profile);
        $this->assertStringContainsString('autoComplete="new-password"', $profile);
    }
}
