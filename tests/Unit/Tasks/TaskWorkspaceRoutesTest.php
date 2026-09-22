<?php

namespace Tests\Unit\Tasks;

use Tests\TestCase;

class TaskWorkspaceRoutesTest extends TestCase
{
    public function test_canonical_workspace_exposes_task_and_notification_routes(): void
    {
        $this->assertSame('/lavr/tasks', route('jarvis.tasks.index', absolute: false));
        $this->assertSame('/lavr/tasks/9/complete', route('jarvis.tasks.complete', ['task' => 9], absolute: false));
        $this->assertSame('/lavr/notifications', route('jarvis.notifications.index', absolute: false));
        $this->assertSame('/lavr/settings/productivity', route('jarvis.settings.productivity.update', absolute: false));
    }

    public function test_header_keeps_three_distinct_entries(): void
    {
        $workspace = file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $tasks = file_get_contents(base_path('resources/js/personal-workspace/TasksPanel.jsx'));
        $inbox = file_get_contents(base_path('resources/js/personal-workspace/NotificationsPanel.jsx'));

        $this->assertStringContainsString('capabilities.tasks', $workspace);
        $this->assertStringContainsString('capabilities.notifications', $workspace);
        $this->assertStringContainsString("aria-label={t('chat.tasks')}", $workspace);
        $this->assertStringContainsString("aria-label={t('chat.notifications')}", $workspace);
        $this->assertStringContainsString("aria-label={t('chat.reminders')}", $workspace);
        $ru = (string) file_get_contents(base_path('resources/js/locales/ru.js'));
        $this->assertStringContainsString("tasks: 'Задачи'", $ru);
        $this->assertStringContainsString("notifications: 'Уведомления'", $ru);
        $this->assertStringContainsString('canUseProjects', $tasks);
        $this->assertStringContainsString('Сегодня', $tasks);
        $this->assertStringContainsString('Просрочено', $tasks);
        $this->assertStringContainsString('Без срока', $tasks);
        $this->assertStringContainsString('Непрочитанные', $inbox);
        $this->assertStringContainsString('Daily Brief', file_get_contents(base_path('resources/js/personal-workspace/settings/ProductivitySettings.jsx')));
        $this->assertStringNotContainsString('project_id && !capabilities.projects', $tasks);
    }

    public function test_ordinary_user_task_panel_hides_project_controls_without_capability_flag(): void
    {
        $tasks = file_get_contents(base_path('resources/js/personal-workspace/TasksPanel.jsx'));

        $this->assertStringContainsString('canUseProjects', $tasks);
        $this->assertStringContainsString('Без проекта', $tasks);
        $this->assertStringContainsString('{canUseProjects ? (', $tasks);
    }
}
