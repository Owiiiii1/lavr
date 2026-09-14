<?php

namespace App\Services\Sources;

use App\Enums\ProjectSourceType;
use App\Models\IntegrationAccount;
use App\Models\ProjectSourceBinding;
use App\Models\SourceItem;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Integrations\Google\GoogleConnectionService;
use App\Services\Integrations\IntegrationAccountService;

final class SourceDeletionService
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly GoogleConnectionService $google,
    ) {}

    public function disable(User $user, IntegrationAccount $account): IntegrationAccount
    {
        $this->accounts->getAccount($user, $account->id, $account->provider);

        return $this->accounts->setEnabled($account, false);
    }

    public function removeGoogle(User $user, IntegrationAccount $account): bool
    {
        $this->unbindSources($user, [ProjectSourceType::GoogleMailbox, ProjectSourceType::GoogleCalendar, ProjectSourceType::IntegrationAccount], (int) $account->id);
        SourceItem::query()
            ->where('user_id', $user->id)
            ->where('integration_account_id', $account->id)
            ->delete();

        return $this->google->disconnect($user, $account);
    }

    public function disableTelegramMonitoring(TelegramGroup $group): TelegramGroup
    {
        $settings = is_array($group->settings) ? $group->settings : [];
        $settings['monitoring_enabled'] = false;
        $group->forceFill(['settings' => $settings])->save();

        return $group->fresh() ?? $group;
    }

    public function removeTelegramSource(User $user, TelegramGroup $group): void
    {
        $this->unbindSources($user, [ProjectSourceType::TelegramGroup], (int) $group->id);
        SourceItem::query()
            ->where('user_id', $user->id)
            ->where('source_instance', 'telegram_group:'.$group->id)
            ->delete();
        $this->disableTelegramMonitoring($group);
    }

    /**
     * @param  list<ProjectSourceType>  $types
     */
    private function unbindSources(User $user, array $types, int $sourceId): void
    {
        $values = array_map(fn (ProjectSourceType $type): string => $type->value, $types);
        ProjectSourceBinding::query()
            ->whereIn('source_type', $values)
            ->where('source_id', $sourceId)
            ->whereHas('project', fn ($query) => $query->where('user_id', $user->id))
            ->delete();
    }
}
