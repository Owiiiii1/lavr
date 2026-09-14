<?php

namespace App\Services\Readiness;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HeartbeatRecorder
{
    public function recordScheduler(): void
    {
        Cache::put((string) config('readiness.heartbeat_cache_key'), now()->toIso8601String(), 3600);
    }

    public function recordQueue(): void
    {
        Cache::put((string) config('readiness.queue_heartbeat_cache_key'), now()->toIso8601String(), 3600);
    }

    public function lastScheduler(): ?string
    {
        $value = Cache::get((string) config('readiness.heartbeat_cache_key'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function lastQueue(): ?string
    {
        $value = Cache::get((string) config('readiness.queue_heartbeat_cache_key'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{pending: int, failed: int, oldest_pending_seconds: int|null}
     */
    public function queueCounts(): array
    {
        $pending = 0;
        $failed = 0;
        $oldest = null;

        if (Schema::hasTable('jobs')) {
            $pending = (int) DB::table('jobs')->count();
            $oldestAt = DB::table('jobs')->min('created_at');
            if (is_numeric($oldestAt)) {
                $oldest = max(0, now()->getTimestamp() - (int) $oldestAt);
            } elseif (is_string($oldestAt) && $oldestAt !== '') {
                $oldest = max(0, now()->diffInSeconds(Carbon::parse($oldestAt), true));
            }
        }

        if (Schema::hasTable('failed_jobs')) {
            $failed = (int) DB::table('failed_jobs')->count();
        }

        return [
            'pending' => $pending,
            'failed' => $failed,
            'oldest_pending_seconds' => $oldest,
        ];
    }
}
