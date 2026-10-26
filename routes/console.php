<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule weekly orphaned audio cleanup for background music in R2
Schedule::command('music:clean-orphaned')
    ->weeklyOn(1, '03:00') // Every Monday at 03:00 AM (runs in dry-run mode by default for safety)
    ->withoutOverlapping()
    ->runInBackground();
