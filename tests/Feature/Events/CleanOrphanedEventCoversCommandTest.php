<?php

namespace Tests\Feature\Events;

use App\Console\Commands\CleanOrphanedEventCoversCommand;
use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanOrphanedEventCoversCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    private function putCover(int $ageHours, string $prefix = 'events/covers/', string $ext = 'jpg'): string
    {
        $key = $prefix.Str::uuid().'.'.$ext;
        Storage::disk('r2')->put($key, 'image');
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    /** An event that references some other, valid object so the empty-reference breaker is not tripped. */
    private function anchorEvent(): Event
    {
        return Event::factory()->draft()->create(['cover_image_url' => $this->putCover(100)]);
    }

    public function test_default_run_is_a_dry_run(): void
    {
        $this->anchorEvent();
        $orphan = $this->putCover(48);

        $this->artisan('events:clean-orphaned-covers')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED: No files were deleted.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_dry_run_flag_overrides_force(): void
    {
        $this->anchorEvent();
        $orphan = $this->putCover(48);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_force_deletes_only_old_unreferenced_covers_and_reports_counts(): void
    {
        $anchor = $this->anchorEvent();
        $orphan = $this->putCover(48);
        $recent = $this->putCover(2);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('Scanned: 3 | Referenced: 1 | Within Grace Period: 1 | Invalid Format: 0 | Invalid Timestamp: 0 | Eligible: 1')
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($orphan);
        Storage::disk('r2')->assertExists($recent);
        Storage::disk('r2')->assertExists($anchor->cover_image_url);
    }

    public function test_age_is_taken_from_the_object_not_from_the_event(): void
    {
        // Event rows are ancient, but the stored object was uploaded just now and is unreferenced
        $this->anchorEvent();
        Event::query()->update(['created_at' => now()->subYears(2)]);
        $freshOrphan = $this->putCover(0);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($freshOrphan);
    }

    public function test_old_objects_that_are_still_referenced_are_never_deleted(): void
    {
        $key = $this->putCover(500);
        Event::factory()->draft()->create(['cover_image_url' => $key]);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_objects_referenced_through_a_legacy_public_url_are_protected(): void
    {
        $key = $this->putCover(500);
        Event::factory()->draft()->create(['cover_image_url' => 'https://pub-r2.waai.test/'.$key]);
        $this->anchorEvent();

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_grace_hours_option_is_respected_and_clamped_to_one_hour(): void
    {
        $this->anchorEvent();
        $fiveHours = $this->putCover(5);
        $justNow = $this->putCover(0);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true, '--grace-hours' => 4])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($fiveHours);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true, '--grace-hours' => 0])->assertExitCode(0);
        Storage::disk('r2')->assertExists($justNow);
    }

    public function test_only_the_events_covers_prefix_is_inspected(): void
    {
        $this->anchorEvent();
        $news = $this->putCover(500, 'news/covers/', 'webp');
        $team = $this->putCover(500, 'team/photos/');
        $music = 'music/audio/'.Str::uuid().'.mp3';
        Storage::disk('r2')->put($music, 'x');
        $nested = $this->putCover(500, 'events/covers/archive/');

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);

        foreach ([$news, $team, $music, $nested] as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_malformed_keys_under_the_prefix_are_skipped(): void
    {
        $this->anchorEvent();
        $bad = 'events/covers/not-a-uuid.jpg';
        Storage::disk('r2')->put($bad, 'x');
        touch(Storage::disk('r2')->path($bad), Carbon::now()->subHours(500)->getTimestamp());

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('Skipping key with unexpected format')
            ->expectsOutputToContain('Invalid Format: 1')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($bad);
    }

    public function test_missing_or_invalid_timestamps_keep_the_object(): void
    {
        $this->anchorEvent();
        $keyA = 'events/covers/'.Str::uuid().'.jpg';
        $keyB = 'events/covers/'.Str::uuid().'.jpg';

        $this->mock(EventCoverImageService::class, function ($mock) use ($keyA, $keyB) {
            $mock->shouldReceive('allCoverKeys')->andReturn([$keyA, $keyB]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->with($keyA)->andThrow(new \RuntimeException('no metadata'));
            $mock->shouldReceive('lastModified')->with($keyB)->andReturn(0);
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('Invalid Timestamp: 2')
            ->assertExitCode(0);
    }

    public function test_future_timestamps_keep_the_object(): void
    {
        $this->anchorEvent();
        $key = 'events/covers/'.Str::uuid().'.jpg';

        $this->mock(EventCoverImageService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allCoverKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->addDays(2)->getTimestamp());
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);
    }

    public function test_database_failure_aborts_before_listing_or_deleting(): void
    {
        $orphan = $this->putCover(500);
        DB::shouldReceive('table')->andThrow(new \PDOException('DB lost'));

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_storage_listing_failure_aborts_without_deleting(): void
    {
        $this->anchorEvent();
        $this->mock(EventCoverImageService::class, function ($mock) {
            $mock->shouldReceive('allCoverKeys')->andThrow(new \RuntimeException('R2 listing failed'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('Failed to list event covers from storage')
            ->assertExitCode(1);
    }

    public function test_storage_deletion_failure_is_reported_and_exits_with_failure(): void
    {
        $this->anchorEvent();
        $key = 'events/covers/'.Str::uuid().'.jpg';

        $this->mock(EventCoverImageService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allCoverKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldReceive('isReferenced')->andReturn(false);
            $mock->shouldReceive('safeDeleteKey')->once()->with($key)->andReturn(false);
        });

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('Deleted: 0, Failed: 1')
            ->assertExitCode(1);
    }

    public function test_candidate_referenced_after_the_scan_is_skipped_by_revalidation(): void
    {
        $this->anchorEvent();
        $key = 'events/covers/'.Str::uuid().'.jpg';

        $this->mock(EventCoverImageService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allCoverKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldReceive('isReferenced')->with($key)->andReturn(true); // an event started referencing it after the scan
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('is now referenced in database')
            ->expectsOutputToContain('Skipped on revalidation: 1')
            ->assertExitCode(0);
    }

    public function test_max_deletions_circuit_breaker_deletes_nothing(): void
    {
        $this->anchorEvent();
        $orphans = [$this->putCover(48), $this->putCover(48), $this->putCover(48)];

        $this->artisan('events:clean-orphaned-covers', ['--force' => true, '--max-deletions' => 2])
            ->expectsOutputToContain('exceeds --max-deletions')
            ->assertExitCode(1);

        foreach ($orphans as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_empty_reference_set_is_treated_as_a_wrong_database_signal(): void
    {
        $orphan = $this->putCover(500);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('may indicate the wrong database')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true, '--allow-empty-references' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_overlapping_runs_are_prevented_by_the_lock(): void
    {
        $this->anchorEvent();
        $orphan = $this->putCover(48);

        $lock = Cache::lock(CleanOrphanedEventCoversCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $lock->release();

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_repeated_execution_is_idempotent(): void
    {
        $this->anchorEvent();
        $orphan = $this->putCover(48);

        $this->artisan('events:clean-orphaned-covers', ['--force' => true])->assertExitCode(0);
        $this->artisan('events:clean-orphaned-covers', ['--force' => true])
            ->expectsOutputToContain('No orphaned event covers found eligible for cleanup.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_weekly_schedule_is_registered_with_the_reviewed_safe_mode(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'events:clean-orphaned-covers'));

        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('30 3 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--force', $event->command);
        $this->assertStringContainsString('--grace-hours=24', $event->command);
        $this->assertStringContainsString('--max-deletions=50', $event->command);
    }
}
