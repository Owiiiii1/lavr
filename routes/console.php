<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('jarvis:reminders:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('jarvis:tasks:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

Schedule::command('jarvis:briefs:dispatch')
    ->everyMinute()
    ->withoutOverlapping(10);

Schedule::command('jarvis:proactive:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

Schedule::command('jarvis:watchers:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

Schedule::command('jarvis:reports:dispatch')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

Schedule::command('jarvis:attachments:purge-ephemeral')
    ->hourly()
    ->withoutOverlapping(55);

Schedule::command('jarvis:voice:cleanup-temp')
    ->everyFiveMinutes()
    ->withoutOverlapping(4);

Schedule::command('jarvis:reliability:recover-stale')
    ->everyFifteenMinutes()
    ->withoutOverlapping(20);

Schedule::command('commitments:refresh-statuses')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Schedule::command('automation:recover-stale-runs')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

Schedule::command('queue:work database --queue=analysis,memory,default --stop-when-empty --max-time=50 --tries=3 --timeout=180')
    ->everyMinute()
    ->withoutOverlapping(1);
