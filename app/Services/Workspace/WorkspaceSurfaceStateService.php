<?php

namespace App\Services\Workspace;

use App\Enums\MemoryAnalysisRunStatus;
use App\Enums\MemoryStatus;
use App\Enums\TopicStatus;
use App\Models\Memory;
use App\Models\MemoryAnalysisRun;
use App\Models\Topic;
use App\Models\User;
use App\Services\Assistant\AssistantProfileService;
use App\Services\Integrations\IntegrationRegistry;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Reminders\ReminderService;
use App\Services\Reports\ScheduledReportService;
use App\Services\Sources\SourceDashboardService;
use App\Services\Tasks\TaskService;
use App\Services\Users\UserCapability;
use App\Services\Users\UserChannelPreferenceService;
use App\Services\Watchers\WatcherService;
use App\Services\WebResearch\WebResearchSettingsService;
use Throwable;

final class WorkspaceSurfaceStateService
{
    public function __construct(
        private readonly ReminderService $reminders,
        private readonly TaskService $tasks,
        private readonly WatcherService $watchers,
        private readonly ScheduledReportService $reports,
        private readonly JarvisNotificationService $notifications,
        private readonly AssistantProfileService $assistantProfiles,
        private readonly IntegrationRegistry $integrations,
        private readonly UserChannelPreferenceService $channelPreferences,
        private readonly WebResearchSettingsService $webResearch,
        private readonly SourceDashboardService $sources,
    ) {}

    /**
     * Lightweight counts and live settings snapshot after a foreground turn.
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $user->loadMissing('aiSettings', 'telegramIdentity');

        return [
            'tasks' => [
                'active_count' => $this->tasks->activeOpenCount($user),
            ],
            'watchers' => [
                'active_count' => $this->watchers->activeCount($user),
            ],
            'reports' => [
                'active_count' => $this->reports->activeCount($user),
            ],
            'reminders' => [
                'active_count' => $this->reminders->activeCount($user),
            ],
            'notifications' => [
                'unread_count' => $user->canUseCapability(UserCapability::NOTIFICATIONS)
                    ? $this->notifications->unreadCount($user)
                    : 0,
            ],
            'assistant_profile' => $this->assistantProfiles->workspacePayload($user),
            'general_prompt' => $user->aiSettings?->general_prompt,
            'telegram' => $this->telegramSummary($user, includeAccessCode: false),
            'memory' => $this->memorySummary($user),
        ];
    }

    /**
     * Settings drawer extras. Not rendered on the main Workspace chrome.
     *
     * @return array<string, mixed>
     */
    public function settingsContext(User $user): array
    {
        $user->loadMissing('aiSettings', 'telegramIdentity');

        return [
            'memory' => $this->memorySummary($user),
            'integrations' => $this->integrationsForSettings($user),
            'google_accounts' => $user->isOwner() && $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)
                ? $this->sources->googleAccounts($user)
                : [],
            'telegram' => $this->telegramSummary($user, includeAccessCode: true),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function turnCounts(User $user): array
    {
        return [
            'active_reminder_count' => $this->reminders->activeCount($user),
            'active_task_count' => $this->tasks->activeOpenCount($user),
            'active_watcher_count' => $this->watchers->activeCount($user),
            'active_report_count' => $this->reports->activeCount($user),
            'unread_notification_count' => $user->canUseCapability(UserCapability::NOTIFICATIONS)
                ? $this->notifications->unreadCount($user)
                : 0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function memorySummary(User $user): ?array
    {
        if (! $user->canUseCapability(UserCapability::MEMORY)) {
            return null;
        }

        $facts = Memory::query()
            ->where('user_id', $user->id)
            ->where('status', MemoryStatus::Active)
            ->count();

        $topics = Topic::query()
            ->where('user_id', $user->id)
            ->where('status', TopicStatus::Active)
            ->count();

        $lastRun = MemoryAnalysisRun::query()
            ->where('user_id', $user->id)
            ->where('status', MemoryAnalysisRunStatus::Completed)
            ->orderByDesc('completed_at')
            ->first();

        return [
            'facts_count' => $facts,
            'topics_count' => $topics,
            'last_analysis_at' => optional($lastRun?->completed_at)?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrationsForSettings(User $user): array
    {
        if (! $user->isOwner() || ! $user->canUseCapability(UserCapability::INTEGRATIONS_ADMIN)) {
            return [];
        }

        try {
            $statuses = $this->integrations->listForOwner($user);
        } catch (Throwable) {
            return [$this->webResearch->workspaceSummary()];
        }

        return array_values(array_merge(
            [$this->webResearch->workspaceSummary()],
            array_map(static function ($status): array {
                $capabilities = [];

                foreach ($status->capabilityStates as $capability) {
                    $capabilities[] = [
                        'key' => (string) ($capability['key'] ?? ''),
                        'label' => (string) ($capability['label'] ?? ''),
                        'state' => (string) ($capability['state'] ?? ''),
                    ];
                }

                return [
                    'provider' => $status->provider,
                    'display_name' => $status->displayName,
                    'state' => $status->state->value,
                    'label' => $status->label,
                    'account_label' => $status->accountLabel,
                    'configured' => $status->configured,
                    'capabilities' => $capabilities,
                ];
            }, $statuses),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function telegramSummary(User $user, bool $includeAccessCode): ?array
    {
        if (! $user->canUseCapability(UserCapability::TELEGRAM_DM)) {
            return null;
        }

        $identity = $user->telegramIdentity;
        $username = trim((string) ($identity?->username ?? ''));
        $firstName = trim((string) ($identity?->first_name ?? ''));
        $accountLabel = $username !== ''
            ? '@'.$username
            : ($firstName !== '' ? $firstName : null);

        $payload = [
            'connected' => $identity !== null,
            'account_label' => $accountLabel,
            'response_mode' => $this->channelPreferences->telegramResponseMode($user)->value,
        ];

        if ($includeAccessCode) {
            $payload['access_code'] = (string) $user->access_code;
        }

        return $payload;
    }
}
