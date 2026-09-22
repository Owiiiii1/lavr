<?php

namespace App\Services\Readiness;

use App\Enums\AcceptanceState;
use App\Enums\AiRoleKey;
use App\Enums\IntegrationAccountStatus;
use App\Enums\IntegrationHealth;
use App\Enums\ReadinessState;
use App\Enums\SystemHealthStatus;
use App\Enums\UserRole;
use App\Models\AiProviderSetting;
use App\Models\AiRoleSetting;
use App\Models\ChannelIdentity;
use App\Models\ExecutiveBrief;
use App\Models\IntegrationAccount;
use App\Models\LeadershipReview;
use App\Models\TelegramBotSetting;
use App\Models\TelegramGroup;
use App\Models\User;
use App\Models\ZoomWebhookEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class LavrDiagnosticsService
{
    public function __construct(
        private readonly HeartbeatRecorder $heartbeats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?User $owner = null): array
    {
        $owner ??= User::query()->where('role', UserRole::Owner)->first();

        $checks = [
            'database' => $this->database(),
            'cache' => $this->cache(),
            'storage' => $this->storage(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'frontend_build' => $this->frontend(),
            'owner' => $this->owner($owner),
            'registration' => $this->registration(),
            'telegram_bot' => $this->telegramBot(),
            'telegram_owner' => $this->telegramOwner($owner),
            'telegram_groups' => $this->telegramGroups(),
            'google' => $this->google($owner),
            'calendar' => $this->google($owner, 'calendar'),
            'zoom' => $this->zoom($owner),
            'ai' => $this->ai(),
            'executive_brief' => $this->lastRow(ExecutiveBrief::class, 'executive_briefs'),
            'leadership_review' => $this->lastRow(LeadershipReview::class, 'leadership_reviews'),
            'operational_control' => $this->operationalScan(),
            'app_debug' => $this->appDebug(),
            'owner_password_rotation' => $this->passwordRotation(),
        ];

        return [
            'generated_at' => now()->toIso8601String(),
            'app_env' => (string) config('app.env'),
            'overall' => $this->overall($checks),
            'checklist' => $this->checklist($checks),
            'sections' => $this->sections($checks),
            'live_validation' => $this->liveValidation(),
            'checks' => $checks,
            'metrics' => $this->metrics($owner),
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return list<array<string, mixed>>
     */
    private function checklist(array $checks): array
    {
        $map = [
            'telegram_owner' => 'Telegram Owner linked',
            'google' => 'Google',
            'zoom' => 'Zoom',
            'ai' => 'AI provider',
            'scheduler' => 'Scheduler',
            'queue' => 'Queue',
            'owner_password_rotation' => 'Owner credential rotation',
        ];

        $items = [];
        foreach ($map as $key => $label) {
            $status = (string) ($checks[$key]['readiness'] ?? ReadinessState::NotConfigured->value);
            $items[] = [
                'key' => $key,
                'label' => $label,
                'state' => $status,
                'detail' => (string) ($checks[$key]['message'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return list<array{title: string, keys: list<string>}>
     */
    private function sections(array $checks): array
    {
        $groups = [
            'Core' => ['database', 'cache', 'owner', 'registration', 'frontend_build'],
            'Integrations' => ['telegram_bot', 'telegram_owner', 'telegram_groups', 'google', 'calendar', 'zoom', 'ai'],
            'Automation' => ['scheduler', 'queue', 'executive_brief', 'leadership_review', 'operational_control'],
            'Security' => ['app_debug', 'owner_password_rotation', 'registration'],
            'Storage' => ['storage'],
            'Validation' => [],
            'Handover' => ['owner_password_rotation'],
        ];

        $sections = [];
        foreach ($groups as $title => $keys) {
            $items = [];
            if ($title === 'Validation') {
                foreach ($this->liveValidation() as $row) {
                    $items[] = [
                        'key' => $row['label'],
                        'acceptance' => $row['state'],
                        'status' => SystemHealthStatus::NotConfigured->value,
                        'message' => 'Live campaign not run.',
                    ];
                }
            }
            foreach ($keys as $key) {
                if (! isset($checks[$key])) {
                    continue;
                }
                $items[] = [
                    'key' => $key,
                    'acceptance' => $checks[$key]['acceptance'] ?? AcceptanceState::NotValidated->value,
                    'status' => $checks[$key]['status'] ?? SystemHealthStatus::NotConfigured->value,
                    'message' => $checks[$key]['message'] ?? '',
                ];
            }
            $sections[] = [
                'title' => $title,
                'items' => $items,
            ];
        }

        return $sections;
    }

    /**
     * @return list<array{label: string, state: string}>
     */
    private function liveValidation(): array
    {
        return [
            ['label' => 'Telegram Mini App E2E', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Google multi-account', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Zoom live ingest', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Commitments live extraction', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Executive Brief live', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Leadership Review live', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Proactive campaign', 'state' => AcceptanceState::NotValidated->value],
            ['label' => 'Handover cleanup against developer accounts', 'state' => AcceptanceState::NotValidated->value],
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     */
    private function overall(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['acceptance'] ?? null) === AcceptanceState::Fail->value) {
                return AcceptanceState::Fail->value;
            }
        }

        foreach ($checks as $check) {
            if (($check['acceptance'] ?? null) === AcceptanceState::Warn->value) {
                return AcceptanceState::Warn->value;
            }
        }

        return AcceptanceState::Pass->value;
    }

    /**
     * @return array<string, mixed>
     */
    private function check(SystemHealthStatus $health, ReadinessState $readiness, AcceptanceState $acceptance, string $message, array $extra = []): array
    {
        return array_merge([
            'status' => $health->value,
            'readiness' => $readiness->value,
            'acceptance' => $acceptance->value,
            'message' => $message,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        try {
            DB::select('select 1');

            return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Database reachable.');
        } catch (\Throwable) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Database unreachable.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function cache(): array
    {
        try {
            Cache::put('lavr:diagnostics:ping', 'ok', 10);

            return Cache::get('lavr:diagnostics:ping') === 'ok'
                ? $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Cache writable.')
                : $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Cache did not round-trip.');
        } catch (\Throwable) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Cache unavailable.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        $disk = Storage::disk((string) config('meetings.disk', 'local'));
        $writable = is_writable(storage_path('app')) && is_writable(storage_path('logs'));
        $meetings = false;
        try {
            $meetings = $disk->exists('.') || $disk->put('_lavr_health.txt', 'ok');
            if ($disk->exists('_lavr_health.txt')) {
                $disk->delete('_lavr_health.txt');
            }
        } catch (\Throwable) {
            $meetings = false;
        }

        $freePercent = null;
        $path = storage_path();
        if (is_dir($path)) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (is_float($total) && $total > 0 && is_float($free)) {
                $freePercent = (int) round(100 - (($free / $total) * 100));
            }
        }

        $warn = is_int($freePercent) && $freePercent >= (int) config('readiness.disk_warn_percent', 90);

        if (! $writable || ! $meetings) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Storage is not writable.', ['used_percent' => $freePercent]);
        }

        if ($warn) {
            return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Disk usage is high.', ['used_percent' => $freePercent]);
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Storage writable.', ['used_percent' => $freePercent]);
    }

    /**
     * @return array<string, mixed>
     */
    private function queue(): array
    {
        $counts = $this->heartbeats->queueCounts();
        $last = $this->heartbeats->lastQueue();

        if ($last === null) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'No queue worker heartbeat yet.', $counts + ['last_heartbeat' => $last]);
        }

        $stale = $this->isStale($last, (int) config('readiness.queue_stale_seconds', 900));

        if ($stale) {
            return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Queue heartbeat is stale.', $counts + ['last_heartbeat' => $last]);
        }

        if ($counts['failed'] > 0) {
            return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Failed jobs are present.', $counts + ['last_heartbeat' => $last]);
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Queue is processing.', $counts + ['last_heartbeat' => $last]);
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        $last = $this->heartbeats->lastScheduler();
        if ($last === null) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'No scheduler heartbeat yet.');
        }

        if ($this->isStale($last, (int) config('readiness.scheduler_stale_seconds', 300))) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Scheduler heartbeat is stale.', ['last_heartbeat' => $last]);
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Scheduler heartbeat is fresh.', ['last_heartbeat' => $last]);
    }

    /**
     * @return array<string, mixed>
     */
    private function frontend(): array
    {
        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Frontend build manifest present.');
        }

        return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Frontend build manifest missing.');
    }

    /**
     * @return array<string, mixed>
     */
    private function owner(?User $owner): array
    {
        if ($owner === null) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Owner user is missing.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Owner exists.', ['id' => $owner->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(): array
    {
        if (Route::has('register')) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'Registration route is present.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Public registration is disabled.');
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramBot(): array
    {
        if (! Schema::hasTable('telegram_bot_settings')) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Telegram settings table missing.');
        }

        $settings = TelegramBotSetting::query()->first();
        if ($settings === null || ! $settings->is_connected) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Telegram bot is not configured.');
        }

        if (! $settings->is_webhook_set) {
            return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Telegram bot connected; webhook not set.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Telegram bot connected.', [
            'username' => $settings->bot_username,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramOwner(?User $owner): array
    {
        if ($owner === null) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Owner missing.');
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $owner->id);
        if ($identity === null) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Telegram Owner is not linked.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Telegram Owner is linked.');
    }

    /**
     * @return array<string, mixed>
     */
    private function telegramGroups(): array
    {
        if (! Schema::hasTable('telegram_groups')) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Telegram groups not available.');
        }

        $count = (int) TelegramGroup::query()->count();

        return $this->check(
            $count > 0 ? SystemHealthStatus::Healthy : SystemHealthStatus::NotConfigured,
            $count > 0 ? ReadinessState::Ready : ReadinessState::NotConfigured,
            AcceptanceState::Pass,
            $count > 0 ? $count.' Telegram group(s).' : 'No Telegram groups yet.',
            ['count' => $count],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function google(?User $owner, string $mode = 'accounts'): array
    {
        if ($owner === null || ! Schema::hasTable('integration_accounts')) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Google is not configured.');
        }

        $accounts = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'google')
            ->get();

        if ($accounts->isEmpty()) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'No Google accounts connected.');
        }

        $blocked = $accounts->first(fn (IntegrationAccount $account): bool => $account->health === IntegrationHealth::Blocked
            || $account->status === IntegrationAccountStatus::Error);

        if ($blocked !== null) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Warn, $blocked->label().' needs reconnect.', [
                'label' => $blocked->label(),
                'count' => $accounts->count(),
            ]);
        }

        $message = $mode === 'calendar'
            ? 'Google Calendar accounts connected.'
            : 'Google accounts connected.';

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, $message, [
            'count' => $accounts->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function zoom(?User $owner): array
    {
        if ($owner === null || ! Schema::hasTable('integration_accounts')) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Zoom is not configured.');
        }

        $account = IntegrationAccount::query()
            ->where('user_id', $owner->id)
            ->where('provider', 'zoom')
            ->first();

        if ($account === null || $account->status === IntegrationAccountStatus::Disconnected) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Zoom is not configured.');
        }

        if ($account->health === IntegrationHealth::Blocked) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Zoom needs reconnect.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Zoom is connected.');
    }

    /**
     * @return array<string, mixed>
     */
    private function ai(): array
    {
        if (! Schema::hasTable('ai_provider_settings') || ! Schema::hasTable('ai_role_settings')) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'AI configuration tables missing.');
        }

        $role = AiRoleSetting::query()
            ->where('role_key', AiRoleKey::OwnerConversation->value)
            ->where('is_enabled', true)
            ->first();

        if ($role === null || ! filled($role->provider) || ! filled($role->model)) {
            return $this->check(SystemHealthStatus::Disabled, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Owner Conversation AI is disabled.');
        }

        $credential = AiProviderSetting::query()
            ->where('provider', $role->provider)
            ->where('is_connected', true)
            ->first();

        if ($credential === null || ! filled($credential->api_key)) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'AI provider needs reconnect.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'AI provider connected.', [
            'provider' => $role->provider,
            'model' => $role->model,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastRow(string $class, string $table): array
    {
        if (! Schema::hasTable($table)) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Warn, 'Not installed.');
        }

        $latest = $class::query()->orderByDesc('id')->first();
        if ($latest === null) {
            return $this->check(SystemHealthStatus::NotConfigured, ReadinessState::NotConfigured, AcceptanceState::Pass, 'No runs yet.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Last record '.$latest->created_at?->toIso8601String(), [
            'id' => $latest->id,
            'created_at' => optional($latest->created_at)?->toIso8601String(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function operationalScan(): array
    {
        $last = $this->heartbeats->lastScheduler();

        return $this->check(
            $last === null ? SystemHealthStatus::NotConfigured : SystemHealthStatus::Healthy,
            $last === null ? ReadinessState::NotConfigured : ReadinessState::Ready,
            AcceptanceState::Pass,
            'Operational scan follows the scheduler heartbeat.',
            ['last_heartbeat' => $last],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function appDebug(): array
    {
        $debug = (bool) config('app.debug');
        if (config('app.env') === 'production' && $debug) {
            return $this->check(SystemHealthStatus::Blocked, ReadinessState::NeedsAttention, AcceptanceState::Fail, 'APP_DEBUG is true in production.');
        }

        return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'APP_DEBUG is '.($debug ? 'true' : 'false').'.');
    }

    /**
     * @return array<string, mixed>
     */
    private function passwordRotation(): array
    {
        if (! (bool) config('readiness.owner_password_rotation_warn', true)) {
            return $this->check(SystemHealthStatus::Healthy, ReadinessState::Ready, AcceptanceState::Pass, 'Owner credential rotation warning disabled.');
        }

        return $this->check(SystemHealthStatus::Degraded, ReadinessState::NeedsAttention, AcceptanceState::Warn, 'Owner credential rotation required before handover.');
    }

    /**
     * @return array<string, mixed>
     */
    private function metrics(?User $owner): array
    {
        $queue = $this->heartbeats->queueCounts();
        $blocked = 0;
        if ($owner !== null && Schema::hasTable('integration_accounts')) {
            $blocked = (int) IntegrationAccount::query()
                ->where('user_id', $owner->id)
                ->where('health', IntegrationHealth::Blocked)
                ->count();
        }

        $telegram = null;
        if (Schema::hasTable('telegram_bot_settings')) {
            $telegram = optional(TelegramBotSetting::query()->first()?->last_webhook_set_at)?->toIso8601String();
        }

        $zoom = null;
        if (Schema::hasTable('zoom_webhook_events')) {
            $zoom = optional(ZoomWebhookEvent::query()->orderByDesc('id')->first()?->created_at)?->toIso8601String();
        }

        return [
            'failed_jobs' => $queue['failed'],
            'pending_jobs' => $queue['pending'],
            'oldest_pending_seconds' => $queue['oldest_pending_seconds'],
            'blocked_integrations' => $blocked,
            'last_telegram_webhook_set_at' => $telegram,
            'last_zoom_webhook_at' => $zoom,
            'last_scheduler_heartbeat' => $this->heartbeats->lastScheduler(),
        ];
    }

    private function isStale(?string $iso, int $seconds): bool
    {
        if ($iso === null || $iso === '') {
            return true;
        }

        try {
            return now()->diffInSeconds(Carbon::parse($iso), true) > $seconds;
        } catch (\Throwable) {
            return true;
        }
    }
}
