<?php

namespace App\Services\Automation;

use App\Enums\ExternalActionLevel;
use App\Models\User;
use App\Services\Productivity\ProductivitySettingsService;

final class ExternalActionPolicy
{
    /**
     * Third-party contact (email, Telegram to another person, calendar write, CRM) defaults to suggest.
     */
    public function levelFor(User $user, string $action): ExternalActionLevel
    {
        $action = mb_strtolower(trim($action));
        $settings = app(ProductivitySettingsService::class)->for($user);

        if (in_array($action, ['read', 'search_gmail', 'list_calendar', 'get_commitment', 'open_source', 'reconnect_integration'], true)) {
            return ExternalActionLevel::Read;
        }

        if (in_array($action, ['create_reminder', 'create_watcher', 'schedule_followup'], true)) {
            return ($settings->auto_create_reminders ?? false) === true
                ? ExternalActionLevel::Execute
                : ExternalActionLevel::Suggest;
        }

        if (in_array($action, ['draft_email', 'draft_telegram_message'], true)) {
            return ($settings->auto_draft_messages ?? false) === true
                ? ExternalActionLevel::Draft
                : ExternalActionLevel::Suggest;
        }

        if ($this->isThirdPartyWrite($action)) {
            return ($settings->third_party_execute ?? false) === true
                ? ExternalActionLevel::Execute
                : ExternalActionLevel::Suggest;
        }

        return ExternalActionLevel::Suggest;
    }

    public function allowsExecute(User $user, string $action): bool
    {
        return $this->levelFor($user, $action)->allowsWrite();
    }

    public function isThirdPartyWrite(string $action): bool
    {
        $action = mb_strtolower($action);

        return (bool) preg_match('/send_gmail|send_email|telegram_send|write_calendar|bitrix|crm_write|notify_person|message_employee/u', $action);
    }
}
