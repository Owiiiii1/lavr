<?php

namespace Tests\Unit\Reminders;

use Tests\TestCase;

class ReminderRoutesTest extends TestCase
{
    public function test_canonical_workspace_exposes_reminder_panel_routes(): void
    {
        $this->assertSame('/lavr/reminders', route('jarvis.reminders.index', absolute: false));
        $this->assertSame('/lavr/reminders/9/cancel', route('jarvis.reminders.cancel', ['reminder' => 9], absolute: false));
        $this->assertSame('/lavr/reminders/9', route('jarvis.reminders.update', ['reminder' => 9], absolute: false));
        $this->assertSame('/lavr/reminders/9/complete', route('jarvis.reminders.complete', ['reminder' => 9], absolute: false));
    }

    public function test_header_entry_is_capability_gated_and_not_telegram_gated(): void
    {
        $workspace = file_get_contents(base_path('resources/js/personal-workspace/PersonalWorkspace.jsx'));
        $panel = file_get_contents(base_path('resources/js/personal-workspace/RemindersPanel.jsx'));

        $this->assertStringContainsString('capabilities.reminders', $workspace);
        $this->assertStringContainsString("aria-label={t('chat.reminders')}", $workspace);
        $this->assertStringContainsString(
            "reminders: 'Напоминания'",
            (string) file_get_contents(base_path('resources/js/locales/ru.js')),
        );
        $this->assertStringContainsString('open-reminder', $workspace);
        $this->assertStringNotContainsString('telegram_connected && capabilities.reminders', $workspace);
        $this->assertStringNotContainsString('Доставка сейчас только в Telegram', $panel);
        $this->assertStringContainsString('Напоминание сохранено в LAVR', $panel);
        $this->assertStringContainsString('Включить уведомления', $panel);
        $this->assertStringContainsString('Выполнено', $panel);
        $this->assertStringContainsString('Отменить', $panel);
        $this->assertStringContainsString('Сегодня', $panel);
        $this->assertStringContainsString('Дальше', $panel);
        $this->assertStringContainsString('История', $panel);
        $this->assertStringNotContainsString('Recurrence пока не поддерживается', $panel);
    }

    public function test_service_worker_is_public_and_allowlists_open_urls(): void
    {
        $sw = file_get_contents(base_path('public/reminder-sw.js'));

        $this->assertStringContainsString("self.addEventListener('push'", $sw);
        $this->assertStringContainsString("self.addEventListener('notificationclick'", $sw);
        $this->assertStringContainsString("path.startsWith('/lavr/')", $sw);
        $this->assertStringContainsString('clients.openWindow', $sw);
        $this->assertStringNotContainsString('event.notification.data.url', substr($sw, (int) strpos($sw, 'openWindow')));
    }
}
