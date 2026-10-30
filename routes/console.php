<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Weekly orphaned audio cleanup for background music (music/audio/ in R2).
// Reviewed scheduled mode: --force with a 24h minimum object age (R2 last-modified) and a deliberately small
// per-run cap (single active track, so a healthy week has at most a few orphans; more than 5 aborts the run
// without deleting). The run also aborts when no track is recorded (wrong-database signal). An atomic cache
// lock inside the command plus withoutOverlapping() prevent concurrent runs.
// Review candidates first with: php artisan music:clean-orphaned --dry-run
Schedule::command('music:clean-orphaned --force --grace-hours=24 --max-deletions=5')
    ->weeklyOn(1, '03:00') // Every Monday at 03:00 AM
    ->withoutOverlapping(120)
    ->runInBackground();

// Weekly orphaned team photo cleanup (team/photos/ in R2). Same reviewed safe mode as the other modules:
// --force, 24h minimum age, capped deletions, wrong-database abort, atomic lock + withoutOverlapping().
// Review candidates first with: php artisan team:clean-orphaned-photos --dry-run
Schedule::command('team:clean-orphaned-photos --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '04:00') // Every Monday at 04:00 AM (after the music and events cleanups)
    ->withoutOverlapping(120)
    ->runInBackground();

// Weekly orphaned event cover cleanup (events/covers/ in R2).
// Reviewed scheduled mode: --force with a 24h minimum object age (R2 last-modified), a hard cap on the number of
// deletions per run (the run aborts without deleting if more objects are eligible) and an abort when no event
// references any image (wrong-database signal). An atomic cache lock inside the command plus withoutOverlapping()
// prevent concurrent runs. Review candidates first with: php artisan events:clean-orphaned-covers --dry-run
Schedule::command('events:clean-orphaned-covers --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '03:30') // Every Monday at 03:30 AM (30 minutes after the music cleanup)
    ->withoutOverlapping(120)
    ->runInBackground();

// Weekly orphaned client logo cleanup (clients/logos/ in R2). Same reviewed safe mode as the other modules:
// --force, 24h minimum age, capped deletions, wrong-database abort, atomic lock + withoutOverlapping().
// Review candidates first with: php artisan clients:clean-orphaned-logos --dry-run
Schedule::command('clients:clean-orphaned-logos --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '04:30') // Every Monday at 04:30 AM (after the team photos cleanup)
    ->withoutOverlapping(120)
    ->runInBackground();

// Weekly orphaned news image cleanup (news/covers/ and news/editor/ in R2). Same reviewed safe mode:
// --force, 24h minimum age, capped deletions, wrong-database abort, atomic lock + withoutOverlapping().
// Review candidates first with: php artisan news:clean-orphaned-images --dry-run
Schedule::command('news:clean-orphaned-images --force --grace-hours=24 --max-deletions=50')
    ->weeklyOn(1, '05:00') // Every Monday at 05:00 AM (after the client logos cleanup)
    ->withoutOverlapping(120)
    ->runInBackground();
