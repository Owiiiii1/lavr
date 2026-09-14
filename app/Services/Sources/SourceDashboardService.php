<?php

namespace App\Services\Sources;

use App\Enums\IntegrationAccountStatus;
use App\Enums\ProjectSourceType;
use App\Models\IntegrationAccount;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Services\Integrations\Google\GoogleCredentialService;
use App\Services\Integrations\Google\GoogleOAuthService;
use App\Services\Integrations\IntegrationAccountService;
use App\Services\Sources\Connectors\MockExternalConnector;
use App\Services\Zoom\ZoomCredentialService;

final class SourceDashboardService
{
    public function __construct(
        private readonly IntegrationAccountService $accounts,
        private readonly ProjectSourceBindingService $bindings,
        private readonly GoogleOAuthService $oauth,
        private readonly GoogleCredentialService $credentials,
        private readonly MockExternalConnector $external,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function workspace(User $user): array
    {
        return [
            'google' => $this->googleAccounts($user),
            'telegram' => $this->telegramGroups($user),
            'zoom' => app(ZoomCredentialService::class)->adminPayload($user),
            'external' => $this->external->health($user),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function googleAccounts(User $user): array
    {
        return $this->accounts->listAccounts($user, 'google')
            ->map(function (IntegrationAccount $account) use ($user): array {
                $envelope = $this->accounts->getCredentials($account);
                $scopes = is_array($account->scopes) ? $account->scopes : [];
                $projects = $this->bindings->projectIdsForSource($user, ProjectSourceType::GoogleMailbox, (int) $account->id);
                $calendarProjects = $this->bindings->projectIdsForSource($user, ProjectSourceType::GoogleCalendar, (int) $account->id);

                return [
                    'id' => $account->id,
                    'email' => $account->external_account_email,
                    'label' => $account->label(),
                    'enabled' => $account->enabled === true,
                    'status' => $account->status instanceof IntegrationAccountStatus ? $account->status->value : (string) $account->status,
                    'health' => $account->health?->value,
                    'gmail_connected' => $this->oauth->hasGmailReadScope($scopes) || $this->oauth->hasGmailScope($scopes),
                    'calendar_connected' => $this->oauth->hasCalendarScope($scopes),
                    'token_health' => $this->credentials->healthFromEnvelope($envelope),
                    'has_encrypted_credentials' => $envelope !== [],
                    'last_success_at' => optional($account->last_success_at)?->toIso8601String(),
                    'last_event_at' => optional($account->last_event_at)?->toIso8601String(),
                    'last_processed_at' => optional($account->last_processed_at)?->toIso8601String(),
                    'last_error_code' => $account->last_error_code,
                    'last_error_message' => $account->last_error_message,
                    'project_ids' => array_values(array_unique(array_merge($projects, $calendarProjects))),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function telegramGroups(User $user): array
    {
        return TelegramGroup::query()
            ->whereHas('conversation', fn ($query) => $query->where('user_id', $user->id))
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(function (TelegramGroup $group) use ($user): array {
                $settings = is_array($group->settings) ? $group->settings : [];

                return [
                    'id' => $group->id,
                    'title' => $group->title,
                    'username' => $group->username,
                    'status' => $group->status->value,
                    'monitoring_enabled' => (bool) ($settings['monitoring_enabled'] ?? false),
                    'last_message_at' => optional($group->last_message_at)?->toIso8601String(),
                    'message_count' => $group->message_count,
                    'project_ids' => $this->bindings->projectIdsForSource($user, ProjectSourceType::TelegramGroup, (int) $group->id),
                ];
            })
            ->values()
            ->all();
    }
}
