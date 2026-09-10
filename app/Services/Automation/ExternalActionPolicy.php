<?php

namespace App\Services\Automation;

use App\Enums\ExternalActionLevel;
use App\Models\User;

final class ExternalActionPolicy
{
    /**
     * Third-party contact (email, Telegram to another person, calendar write, CRM) defaults to suggest.
     */
    public function levelFor(User $user, string $action): ExternalActionLevel
    {
        unset($user);
        $action = mb_strtolower(trim($action));

        if (in_array($action, ['read', 'search_gmail', 'list_calendar', 'get_commitment'], true)) {
            return ExternalActionLevel::Read;
        }

        if ($this->isThirdPartyWrite($action)) {
            return ExternalActionLevel::Suggest;
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
