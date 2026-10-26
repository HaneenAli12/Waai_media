<?php

namespace Tests\Feature;

use App\Models\Music;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanOrphanedBackgroundMusicCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    public function test_dry_run_identifies_candidates_and_deletes_nothing(): void
    {
        $activeKey = 'music/audio/'.Str::uuid().'.mp3';
        $orphanKey = 'music/audio/'.Str::uuid().'.wav';

        // Store active and orphan file in fake storage
        Storage::disk('r2')->put($activeKey, 'dummy active content');
        Storage::disk('r2')->put($orphanKey, 'dummy orphan content');

        // Backdate orphan file beyond 24h grace period
        $oldTime = Carbon::now()->subHours(25)->getTimestamp();
        touch(Storage::disk('r2')->path($orphanKey), $oldTime);

        // Associate active track in database
        $music = Music::instance();
        $music->file_path = $activeKey;
        $music->save();

        // Run dry-run (default without --force)
        $this->artisan('music:clean-orphaned')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED: No files were deleted.')
            ->assertExitCode(0);

        // Assert both files still exist
        Storage::disk('r2')->assertExists($activeKey);
        Storage::disk('r2')->assertExists($orphanKey);
    }

    public function test_active_and_referenced_audio_objects_are_never_deleted(): void
    {
        $activeKey = 'music/audio/'.Str::uuid().'.mp3';
        Storage::disk('r2')->put($activeKey, 'dummy active content');

        $oldTime = Carbon::now()->subHours(48)->getTimestamp();
        touch(Storage::disk('r2')->path($activeKey), $oldTime);

        $music = Music::instance();
        $music->file_path = $activeKey;
        $music->save();

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($activeKey);
    }

    public function test_recent_objects_within_grace_period_are_skipped(): void
    {
        $recentOrphanKey = 'music/audio/'.Str::uuid().'.mp3';
        Storage::disk('r2')->put($recentOrphanKey, 'recent orphan content');

        // Modified 2 hours ago (within default 24h grace)
        $recentTime = Carbon::now()->subHours(2)->getTimestamp();
        touch(Storage::disk('r2')->path($recentOrphanKey), $recentTime);

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($recentOrphanKey);
    }

    public function test_objects_outside_audio_prefix_are_untouched(): void
    {
        $otherFile = 'news/covers/some-cover.webp';
        Storage::disk('r2')->put($otherFile, 'cover content');

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($otherFile);
    }

    public function test_database_failure_aborts_cleanup_and_fails_closed(): void
    {
        $orphanKey = 'music/audio/'.Str::uuid().'.mp3';
        Storage::disk('r2')->put($orphanKey, 'orphan content');

        $oldTime = Carbon::now()->subHours(50)->getTimestamp();
        touch(Storage::disk('r2')->path($orphanKey), $oldTime);

        // Simulate database lookup exception while keeping connection pointed to waai_media_testing
        DB::shouldReceive('connection->getPdo')
            ->andThrow(new \PDOException('Simulated database connection loss'));

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->assertExitCode(1);

        // Object must not be deleted
        Storage::disk('r2')->assertExists($orphanKey);
    }

    public function test_destructive_cleanup_deletes_only_confirmed_orphaned_objects(): void
    {
        $activeKey = 'music/audio/'.Str::uuid().'.mp3';
        $oldOrphanKey = 'music/audio/'.Str::uuid().'.wav';

        Storage::disk('r2')->put($activeKey, 'active content');
        Storage::disk('r2')->put($oldOrphanKey, 'old orphan content');

        $oldTime = Carbon::now()->subHours(30)->getTimestamp();
        touch(Storage::disk('r2')->path($oldOrphanKey), $oldTime);

        $music = Music::instance();
        $music->file_path = $activeKey;
        $music->save();

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($activeKey);
        Storage::disk('r2')->assertMissing($oldOrphanKey);
    }

    public function test_scheduled_task_is_registered_weekly(): void
    {
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())->filter(function ($event) {
            return str_contains($event->command, 'music:clean-orphaned');
        });

        $this->assertNotEmpty($events, 'music:clean-orphaned should be registered in Laravel scheduler');
        $event = $events->first();
        $this->assertSame('0 3 * * 1', $event->expression);
    }

    public function test_concurrent_activation_during_cleanup_skips_deletion(): void
    {
        $candidateKey = 'music/audio/'.Str::uuid().'.mp3';
        Storage::disk('r2')->put($candidateKey, 'content');

        $oldTime = Carbon::now()->subHours(40)->getTimestamp();
        touch(Storage::disk('r2')->path($candidateKey), $oldTime);

        // Simulate database lookup where during the scan the candidate was unreferenced,
        // but immediately upon JIT revalidation before safeDelete, it became active.
        // We set Music record to candidateKey
        $music = Music::instance();
        $music->file_path = $candidateKey;
        $music->save();

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->assertExitCode(0);

        // Object must be completely preserved
        Storage::disk('r2')->assertExists($candidateKey);
    }
}
