<?php

namespace App\Services\OperationalControl;

use App\Enums\JarvisNotificationSeverity;
use App\Enums\JarvisNotificationType;
use App\Enums\OperationalSeverity;
use App\Enums\ProactiveProposalStatus;
use App\Models\ChannelIdentity;
use App\Models\JarvisNotification;
use App\Models\ProactiveProposal;
use App\Models\User;
use App\Services\Locale\OwnerLocaleResolver;
use App\Services\Notifications\JarvisNotificationService;
use App\Services\Productivity\ProductivitySettingsService;
use App\Services\Reminders\Contracts\SendsReminderTelegram;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Cache;

final class OperationalNotificationGate
{
    public function __construct(
        private readonly ProductivitySettingsService $settings,
        private readonly JarvisNotificationService $inbox,
        private readonly OwnerLocaleResolver $locales,
        private readonly OperationalCopy $copy,
        private readonly ?SendsReminderTelegram $telegram = null,
    ) {}

    public function maybeNotify(User $user, ProactiveProposal $proposal, bool $notify): bool
    {
        if (! $notify || $proposal->status !== ProactiveProposalStatus::Pending) {
            return false;
        }

        $settings = $this->settings->for($user);
        if (($settings->operational_alerts_enabled ?? true) !== true) {
            return false;
        }

        $severity = $proposal->severity instanceof OperationalSeverity
            ? $proposal->severity
            : OperationalSeverity::Normal;

        if ($this->inQuietHours($user, $severity, $settings)) {
            return false;
        }

        if (! $this->underDailyCap($user, $settings)) {
            return false;
        }

        if ($this->inCooldown($proposal)) {
            return false;
        }

        $dedupe = 'operational:'.$proposal->fingerprint;
        $row = $this->inbox->record(
            $user,
            JarvisNotificationType::OperationalAlert,
            $proposal->title,
            $this->copy->alertBody($user, $proposal),
            $dedupe,
            'proactive_proposal',
            (int) $proposal->id,
            '/lavr/proactive/'.$proposal->id,
            [
                'trigger' => 'operational_alert',
                'proposal_id' => $proposal->id,
            ],
        );

        if ($row === null) {
            return false;
        }

        $proposal->forceFill(['last_notified_at' => now()])->save();
        $this->maybeTelegram($user, $proposal);

        return true;
    }

    private function inQuietHours(User $user, OperationalSeverity $severity, mixed $settings): bool
    {
        $start = is_string($settings->quiet_hours_start ?? null) ? $settings->quiet_hours_start : null;
        $end = is_string($settings->quiet_hours_end ?? null) ? $settings->quiet_hours_end : null;
        if ($start === null || $end === null || $start === '' || $end === '') {
            return false;
        }

        if ($severity === OperationalSeverity::Critical && ($settings->critical_bypass_quiet_hours ?? true) === true) {
            return false;
        }

        $timezone = (string) ($user->timezone ?: 'UTC');
        try {
            new DateTimeZone($timezone);
            $local = CarbonImmutable::now('UTC')->setTimezone($timezone)->format('H:i');
        } catch (Exception) {
            $local = CarbonImmutable::now('UTC')->format('H:i');
        }

        if ($start <= $end) {
            return $local >= $start && $local < $end;
        }

        return $local >= $start || $local < $end;
    }

    private function underDailyCap(User $user, mixed $settings): bool
    {
        $cap = (int) ($settings->operational_max_alerts_per_day ?? config('operational_control.default_max_alerts_per_day', 6));
        $timezone = (string) ($user->timezone ?: 'UTC');
        try {
            $start = CarbonImmutable::now('UTC')->setTimezone($timezone)->startOfDay()->utc();
        } catch (Exception) {
            $start = CarbonImmutable::now('UTC')->startOfDay();
        }

        $count = JarvisNotification::query()
            ->where('user_id', $user->id)
            ->where('type', JarvisNotificationType::OperationalAlert)
            ->where('created_at', '>=', $start)
            ->count();

        return $count < max(1, $cap);
    }

    private function inCooldown(ProactiveProposal $proposal): bool
    {
        if ($proposal->last_notified_at === null) {
            return false;
        }

        $hours = max(1, (int) config('operational_control.notification_cooldown_hours', 12));

        return $proposal->last_notified_at->greaterThan(now()->subHours($hours));
    }

    private function maybeTelegram(User $user, ProactiveProposal $proposal): void
    {
        if ($this->telegram === null) {
            return;
        }

        $identity = ChannelIdentity::findTelegramForUser((int) $user->id);
        $chatId = ($identity !== null && filled($identity->external_chat_id))
            ? (string) $identity->external_chat_id
            : null;

        if ($chatId === null) {
            return;
        }

        $lock = Cache::lock('operational-telegram:'.$proposal->fingerprint, 30);
        if (! $lock->get()) {
            return;
        }

        try {
            $this->telegram->send(
                $chatId,
                $this->copy->telegramAlert($user, $proposal),
                'proactive_'.$proposal->id,
            );
        } catch (\Throwable) {
        }
    }

    public function severityForInbox(OperationalSeverity $severity): JarvisNotificationSeverity
    {
        return match ($severity) {
            OperationalSeverity::Critical => JarvisNotificationSeverity::Urgent,
            OperationalSeverity::High => JarvisNotificationSeverity::Warning,
            default => JarvisNotificationSeverity::Info,
        };
    }
}
