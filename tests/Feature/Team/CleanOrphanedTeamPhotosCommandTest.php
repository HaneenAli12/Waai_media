<?php

namespace Tests\Feature\Team;

use App\Console\Commands\CleanOrphanedTeamPhotosCommand;
use App\Models\TeamMember;
use App\Services\Team\TeamPhotoService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanOrphanedTeamPhotosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    private function putPhoto(int $ageHours, string $prefix = 'team/photos/'): string
    {
        $key = $prefix.Str::uuid().'.jpg';
        Storage::disk('r2')->put($key, 'photo');
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    public function test_default_run_is_dry_run_and_deletes_nothing(): void
    {
        $orphan = $this->putPhoto(48);

        $this->artisan('team:clean-orphaned-photos')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED: No files were deleted.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_dry_run_flag_takes_precedence_over_force(): void
    {
        $orphan = $this->putPhoto(48);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_force_deletes_only_old_unreferenced_photos(): void
    {
        $referenced = $this->putPhoto(72);
        TeamMember::factory()->create(['photo_path' => $referenced]);
        $orphan = $this->putPhoto(48);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($referenced);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_recent_unreferenced_photos_are_kept_by_grace_period(): void
    {
        $recent = $this->putPhoto(2);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($recent);
    }

    public function test_grace_hours_option_is_respected(): void
    {
        $key = $this->putPhoto(5);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--grace-hours' => 4, '--allow-empty-references' => true])->assertExitCode(0);

        Storage::disk('r2')->assertMissing($key);
    }

    public function test_grace_hours_below_one_is_clamped_to_one_hour(): void
    {
        $key = $this->putPhoto(0);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--grace-hours' => 0])->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_objects_outside_team_photos_prefix_are_never_touched(): void
    {
        $event = $this->putPhoto(100, 'events/covers/');
        $news = $this->putPhoto(100, 'news/covers/');
        $nested = $this->putPhoto(100, 'team/photos/archive/');

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($event);
        Storage::disk('r2')->assertExists($news);
        Storage::disk('r2')->assertExists($nested);
    }

    public function test_malformed_keys_under_prefix_are_skipped(): void
    {
        $bad = 'team/photos/not-a-uuid.jpg';
        Storage::disk('r2')->put($bad, 'x');
        touch(Storage::disk('r2')->path($bad), Carbon::now()->subHours(100)->getTimestamp());

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('Skipping key with unexpected format')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($bad);
    }

    public function test_database_failure_aborts_and_fails_closed(): void
    {
        $orphan = $this->putPhoto(100);
        $this->mock(TeamPhotoService::class); // no expectations: listing must never be reached
        DB::shouldReceive('table')->andThrow(new \PDOException('DB lost'));

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_storage_listing_failure_aborts_without_deleting(): void
    {
        $this->mock(TeamPhotoService::class, function ($mock) {
            $mock->shouldReceive('allPhotoKeys')->andThrow(new \RuntimeException('R2 listing failed'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('Failed to list team photos from storage')
            ->assertExitCode(1);
    }

    public function test_unknown_last_modified_keeps_object(): void
    {
        $key = 'team/photos/'.Str::uuid().'.jpg';
        $this->mock(TeamPhotoService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allPhotoKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andThrow(new \RuntimeException('no metadata'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('Skipping for safety')
            ->assertExitCode(0);
    }

    public function test_deletion_failure_is_reported_with_failure_exit_code(): void
    {
        $key = 'team/photos/'.Str::uuid().'.jpg';
        $this->mock(TeamPhotoService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allPhotoKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldReceive('safeDeleteKey')->once()->with($key)->andReturn(false);
        });

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--allow-empty-references' => true])
            ->expectsOutputToContain('Deleted: 0, Failed: 1,')
            ->assertExitCode(1);
    }

    public function test_photo_referenced_after_scan_is_skipped_by_revalidation(): void
    {
        $key = 'team/photos/'.Str::uuid().'.jpg';
        $member = null;
        $this->mock(TeamPhotoService::class, function ($mock) use ($key, &$member) {
            // The member starts referencing the key between the scan and the delete step
            $mock->shouldReceive('allPhotoKeys')->andReturnUsing(function () use ($key, &$member) {
                $member = TeamMember::factory()->create(['photo_path' => $key]);

                return [$key];
            });
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldNotReceive('safeDeleteKey');
        });

        // allPhotoKeys runs after the reference lookup, so the scan treats the key as unreferenced
        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--allow-empty-references' => true])
            ->expectsOutputToContain('is now referenced in database')
            ->assertExitCode(0);
    }

    public function test_weekly_schedule_is_registered_with_the_reviewed_safe_mode(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'team:clean-orphaned-photos'));

        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('0 4 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--force', $event->command);
        $this->assertStringContainsString('--grace-hours=24', $event->command);
        $this->assertStringContainsString('--max-deletions=50', $event->command);
    }

    public function test_empty_reference_set_aborts_destructive_run_unless_allowed(): void
    {
        $orphan = $this->putPhoto(48);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('may indicate the wrong database')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--allow-empty-references' => true])
            ->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_empty_reference_set_does_not_block_dry_run(): void
    {
        $orphan = $this->putPhoto(48);

        $this->artisan('team:clean-orphaned-photos')->assertExitCode(0);
        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_max_deletions_limit_aborts_without_deleting_anything(): void
    {
        TeamMember::factory()->create(['photo_path' => $this->putPhoto(72)]);
        $orphans = [$this->putPhoto(48), $this->putPhoto(48), $this->putPhoto(48)];

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--max-deletions' => 2])
            ->expectsOutputToContain('exceeds --max-deletions (2)')
            ->assertExitCode(1);

        foreach ($orphans as $key) {
            Storage::disk('r2')->assertExists($key);
        }

        $this->artisan('team:clean-orphaned-photos', ['--force' => true, '--max-deletions' => 3])->assertExitCode(0);
        foreach ($orphans as $key) {
            Storage::disk('r2')->assertMissing($key);
        }
    }

    public function test_missing_or_invalid_timestamps_keep_the_object(): void
    {
        TeamMember::factory()->create(['photo_path' => $this->putPhoto(72)]);
        $zero = 'team/photos/'.Str::uuid().'.jpg';
        $future = 'team/photos/'.Str::uuid().'.jpg';
        $unreadable = 'team/photos/'.Str::uuid().'.jpg';

        $this->mock(TeamPhotoService::class, function ($mock) use ($zero, $future, $unreadable) {
            $mock->shouldReceive('allPhotoKeys')->andReturn([$zero, $future, $unreadable]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->with($zero)->andReturn(0);
            $mock->shouldReceive('lastModified')->with($future)->andReturn(Carbon::now()->addDays(2)->getTimestamp());
            $mock->shouldReceive('lastModified')->with($unreadable)->andThrow(new \RuntimeException('no timestamp'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('Invalid Timestamp: 3')
            ->assertExitCode(0);
    }

    public function test_overlapping_runs_are_prevented_by_the_lock(): void
    {
        TeamMember::factory()->create(['photo_path' => $this->putPhoto(72)]);
        $orphan = $this->putPhoto(48);

        $lock = Cache::lock(CleanOrphanedTeamPhotosCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $lock->release();

        $this->artisan('team:clean-orphaned-photos', ['--force' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_lock_is_released_after_a_failed_run(): void
    {
        $this->mock(TeamPhotoService::class, function ($mock) {
            $mock->shouldReceive('allPhotoKeys')->andThrow(new \RuntimeException('r2 down'));
        });
        $this->artisan('team:clean-orphaned-photos', ['--force' => true])->assertExitCode(1);

        $lock = Cache::lock(CleanOrphanedTeamPhotosCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
