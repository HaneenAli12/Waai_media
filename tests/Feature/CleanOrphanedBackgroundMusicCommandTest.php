<?php

namespace Tests\Feature;

use App\Console\Commands\CleanOrphanedBackgroundMusicCommand;
use App\Models\Music;
use App\Services\Storage\AudioStorageService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
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

    private function putAudio(int $ageHours, string $ext = 'mp3'): string
    {
        $key = 'music/audio/'.Str::uuid().'.'.$ext;
        Storage::disk('r2')->put($key, 'audio');
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    private function activate(string $key): void
    {
        $music = Music::instance();
        $music->file_path = $key;
        $music->save();
    }

    public function test_dry_run_flag_takes_precedence_over_force(): void
    {
        $this->activate($this->putAudio(72));
        $orphan = $this->putAudio(48);

        $this->artisan('music:clean-orphaned', ['--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_empty_reference_aborts_destructive_run_unless_allowed(): void
    {
        // No active track recorded (e.g. wrong or fresh database) while audio objects exist
        $orphan = $this->putAudio(48);

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('may indicate the wrong database')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $this->artisan('music:clean-orphaned', ['--force' => true, '--allow-empty-references' => true])
            ->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_empty_reference_does_not_block_dry_run(): void
    {
        $orphan = $this->putAudio(48);

        $this->artisan('music:clean-orphaned')->assertExitCode(0);
        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_max_deletions_limit_aborts_without_deleting_anything(): void
    {
        $active = $this->putAudio(72);
        $this->activate($active);
        $orphans = [$this->putAudio(48), $this->putAudio(48, 'wav'), $this->putAudio(48, 'ogg')];

        $this->artisan('music:clean-orphaned', ['--force' => true, '--max-deletions' => 2])
            ->expectsOutputToContain('exceeds --max-deletions (2)')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($active);
        foreach ($orphans as $key) {
            Storage::disk('r2')->assertExists($key);
        }

        $this->artisan('music:clean-orphaned', ['--force' => true, '--max-deletions' => 3])->assertExitCode(0);
        foreach ($orphans as $key) {
            Storage::disk('r2')->assertMissing($key);
        }
        Storage::disk('r2')->assertExists($active);
    }

    public function test_default_max_deletions_is_conservative_for_a_single_record_design(): void
    {
        $this->activate($this->putAudio(72));
        $orphans = [];
        for ($i = 0; $i < 6; $i++) {
            $orphans[] = $this->putAudio(48);
        }

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('exceeds --max-deletions (5)')
            ->assertExitCode(1);

        foreach ($orphans as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_missing_or_invalid_timestamps_keep_the_object(): void
    {
        $this->activate($this->putAudio(72));
        $zero = 'music/audio/'.Str::uuid().'.mp3';
        $future = 'music/audio/'.Str::uuid().'.mp3';
        $unreadable = 'music/audio/'.Str::uuid().'.mp3';

        $service = \Mockery::mock(AudioStorageService::class, ['r2'])->makePartial();
        $service->shouldReceive('allAudioFiles')->andReturn([$zero, $future, $unreadable]);
        $service->shouldReceive('lastModified')->with($zero)->andReturn(0);
        $service->shouldReceive('lastModified')->with($future)->andReturn(Carbon::now()->addDays(2)->getTimestamp());
        $service->shouldReceive('lastModified')->with($unreadable)->andThrow(new \RuntimeException('no timestamp'));
        $service->shouldNotReceive('safeDelete');
        $this->app->instance(AudioStorageService::class, $service);

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('Invalid Timestamp: 3')
            ->assertExitCode(0);
    }

    public function test_storage_listing_failure_aborts_without_deleting(): void
    {
        $service = \Mockery::mock(AudioStorageService::class, ['r2'])->makePartial();
        $service->shouldReceive('allAudioFiles')->andThrow(new \RuntimeException('r2 down'));
        $service->shouldNotReceive('safeDelete');
        $this->app->instance(AudioStorageService::class, $service);

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('Failed to list audio files')
            ->assertExitCode(1);
    }

    public function test_deletion_failure_is_reported_with_failure_exit_code(): void
    {
        $this->activate($this->putAudio(72));
        $orphan = $this->putAudio(48);

        $service = \Mockery::mock(AudioStorageService::class, ['r2'])->makePartial();
        $service->shouldReceive('safeDelete')->once()->with($orphan)->andReturn(false);
        $this->app->instance(AudioStorageService::class, $service);

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('Deleted: 0, Failed: 1')
            ->assertExitCode(1);
    }

    public function test_overlapping_runs_are_prevented_by_the_lock(): void
    {
        $this->activate($this->putAudio(72));
        $orphan = $this->putAudio(48);

        $lock = Cache::lock(CleanOrphanedBackgroundMusicCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $lock->release();

        $this->artisan('music:clean-orphaned', ['--force' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_lock_is_released_after_a_failed_run(): void
    {
        $service = \Mockery::mock(AudioStorageService::class, ['r2'])->makePartial();
        $service->shouldReceive('allAudioFiles')->andThrow(new \RuntimeException('r2 down'));
        $this->app->instance(AudioStorageService::class, $service);
        $this->artisan('music:clean-orphaned', ['--force' => true])->assertExitCode(1);

        $lock = Cache::lock(CleanOrphanedBackgroundMusicCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }

    public function test_repeated_execution_is_idempotent(): void
    {
        $this->activate($this->putAudio(72));
        $orphan = $this->putAudio(48);

        $this->artisan('music:clean-orphaned', ['--force' => true])->assertExitCode(0);
        $this->artisan('music:clean-orphaned', ['--force' => true])
            ->expectsOutputToContain('No orphaned audio files found eligible for cleanup.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_weekly_schedule_runs_destructively_with_conservative_limits(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'music:clean-orphaned'));

        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('0 3 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--force', $event->command);
        $this->assertStringContainsString('--grace-hours=24', $event->command);
        $this->assertStringContainsString('--max-deletions=5', $event->command);
    }
}
