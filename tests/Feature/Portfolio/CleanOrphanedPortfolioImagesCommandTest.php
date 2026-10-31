<?php

namespace Tests\Feature\Portfolio;

use App\Console\Commands\CleanOrphanedPortfolioImagesCommand;
use App\Models\PortfolioMedia;
use App\Services\Portfolio\PortfolioImageService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Feature\Portfolio\Concerns\PortfolioTestHelpers;
use Tests\TestCase;

class CleanOrphanedPortfolioImagesCommandTest extends TestCase
{
    use PortfolioTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPortfolio();
    }

    private function putImage(int $ageHours, string $prefix = 'portfolio/images/'): string
    {
        $key = $prefix.Str::uuid().'.jpg';
        Storage::disk('r2')->put($key, 'image');
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    private function reference(string $key): void
    {
        $item = $this->makeItem('graphic-design');
        PortfolioMedia::create(['portfolio_item_id' => $item->id, 'image_path' => $key, 'sort_order' => 0]);
    }

    public function test_default_run_is_dry_run_and_deletes_nothing(): void
    {
        $orphan = $this->putImage(48);

        $this->artisan('portfolio:clean-orphaned-images')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED: No files were deleted.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_dry_run_flag_takes_precedence_over_force(): void
    {
        $orphan = $this->putImage(48);

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true, '--dry-run' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_force_deletes_only_old_unreferenced_images_and_respects_the_grace_period(): void
    {
        $referenced = $this->putImage(72);
        $this->reference($referenced);
        $orphan = $this->putImage(48);
        $recent = $this->putImage(2);

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($referenced);
        Storage::disk('r2')->assertExists($recent);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_objects_outside_the_portfolio_prefix_are_never_touched(): void
    {
        $this->reference($this->putImage(72));
        $foreign = [
            $this->putImage(100, 'events/covers/'),
            $this->putImage(100, 'clients/logos/'),
            $this->putImage(100, 'team/photos/'),
        ];

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])->assertExitCode(0);

        foreach ($foreign as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_malformed_keys_are_skipped(): void
    {
        $this->reference($this->putImage(72));
        Storage::disk('r2')->put('portfolio/images/not-a-uuid.jpg', 'x');
        touch(Storage::disk('r2')->path('portfolio/images/not-a-uuid.jpg'), Carbon::now()->subDays(5)->getTimestamp());

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Invalid Format: 1')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists('portfolio/images/not-a-uuid.jpg');
    }

    public function test_empty_reference_set_aborts_destructive_run_unless_allowed(): void
    {
        $orphan = $this->putImage(48);

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('may indicate the wrong database')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true, '--allow-empty-references' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_max_deletions_limit_aborts_without_deleting_anything(): void
    {
        $this->reference($this->putImage(72));
        $orphans = [$this->putImage(48), $this->putImage(48), $this->putImage(48)];

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true, '--max-deletions' => 2])
            ->expectsOutputToContain('exceeds --max-deletions (2)')
            ->assertExitCode(1);

        foreach ($orphans as $key) {
            Storage::disk('r2')->assertExists($key);
        }
    }

    public function test_storage_listing_failure_aborts_without_deleting(): void
    {
        $this->mock(PortfolioImageService::class, function ($mock) {
            $mock->shouldReceive('allImageKeys')->andThrow(new \RuntimeException('r2 down'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('Failed to list portfolio images')
            ->assertExitCode(1);
    }

    public function test_deletion_failure_is_reported_with_failure_exit_code(): void
    {
        $key = 'portfolio/images/'.Str::uuid().'.jpg';
        $this->mock(PortfolioImageService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allImageKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldReceive('safeDeleteKey')->once()->with($key)->andReturn(false);
        });

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true, '--allow-empty-references' => true])
            ->expectsOutputToContain('Deleted: 0, Failed: 1')
            ->assertExitCode(1);
    }

    public function test_overlapping_runs_are_prevented_by_the_lock(): void
    {
        $this->reference($this->putImage(72));
        $orphan = $this->putImage(48);

        $lock = Cache::lock(CleanOrphanedPortfolioImagesCommand::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])
            ->expectsOutputToContain('already running')
            ->assertExitCode(1);
        Storage::disk('r2')->assertExists($orphan);

        $lock->release();

        $this->artisan('portfolio:clean-orphaned-images', ['--force' => true])->assertExitCode(0);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_weekly_schedule_is_registered_with_the_reviewed_safe_mode(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'portfolio:clean-orphaned-images'));

        $this->assertCount(1, $events);
        $event = $events->first();

        $this->assertSame('0 5 * * 1', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertStringContainsString('--force', $event->command);
        $this->assertStringContainsString('--grace-hours=24', $event->command);
        $this->assertStringContainsString('--max-deletions=50', $event->command);
    }
}
