<?php

namespace Tests\Feature\Clients;

use App\Console\Commands\CleanOrphanedClientLogosCommand;
use App\Models\Client;
use App\Services\Clients\ClientLogoService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CleanOrphanedClientLogosCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    private function putLogo(int $ageHours, string $prefix = 'clients/logos/'): string
    {
        $key = $prefix.Str::uuid().'.jpg';
        Storage::disk('r2')->put($key, 'logo');
        touch(Storage::disk('r2')->path($key), Carbon::now()->subHours($ageHours)->getTimestamp());

        return $key;
    }

    public function test_default_run_is_dry_run_and_deletes_nothing(): void
    {
        $orphan = $this->putLogo(48);

        $this->artisan('clients:clean-orphaned-logos')
            ->expectsOutputToContain('DRY-RUN MODE ENABLED: No files were deleted.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_dry_run_flag_takes_precedence_over_force(): void
    {
        $orphan = $this->putLogo(48);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--dry-run' => true])
            ->expectsOutputToContain('DRY-RUN MODE ENABLED')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_force_deletes_only_old_unreferenced_logos(): void
    {
        $referenced = $this->putLogo(72);
        Client::factory()->create(['logo_path' => $referenced]);
        $orphan = $this->putLogo(48);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($referenced);
        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_recent_unreferenced_logos_are_kept_by_grace_period(): void
    {
        $recent = $this->putLogo(2);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($recent);
    }

    public function test_grace_hours_option_is_respected(): void
    {
        $key = $this->putLogo(5);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--grace-hours' => 4, '--allow-empty-references' => true])->assertExitCode(0);

        Storage::disk('r2')->assertMissing($key);
    }

    public function test_grace_hours_below_one_is_clamped_to_one_hour(): void
    {
        $key = $this->putLogo(0);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--grace-hours' => 0])->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_objects_outside_client_logos_prefix_are_never_touched(): void
    {
        $event = $this->putLogo(100, 'events/covers/');
        $news = $this->putLogo(100, 'news/covers/');
        $team = $this->putLogo(100, 'team/photos/');
        $nested = $this->putLogo(100, 'clients/logos/archive/');

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])->assertExitCode(0);

        Storage::disk('r2')->assertExists($event);
        Storage::disk('r2')->assertExists($news);
        Storage::disk('r2')->assertExists($team);
        Storage::disk('r2')->assertExists($nested);
    }

    public function test_malformed_keys_under_prefix_are_skipped(): void
    {
        $bad = 'clients/logos/not-a-uuid.jpg';
        Storage::disk('r2')->put($bad, 'x');
        touch(Storage::disk('r2')->path($bad), Carbon::now()->subHours(100)->getTimestamp());

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain('Skipping key with unexpected format')
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($bad);
    }

    public function test_database_failure_aborts_and_fails_closed(): void
    {
        $orphan = $this->putLogo(100);
        $this->mock(ClientLogoService::class);
        DB::shouldReceive('table')->andThrow(new \PDOException('DB lost'));

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_storage_listing_failure_aborts_without_deleting(): void
    {
        $this->mock(ClientLogoService::class, function ($mock) {
            $mock->shouldReceive('allLogoKeys')->andThrow(new \RuntimeException('R2 listing failed'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain('Failed to list client logos from storage')
            ->assertExitCode(1);
    }

    public function test_unknown_last_modified_keeps_object(): void
    {
        $key = $this->putLogo(100);

        $this->mock(ClientLogoService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allLogoKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->with($key)->andReturn(true);
            $mock->shouldReceive('lastModified')->with($key)->andThrow(new \RuntimeException('Metadata unavailable'));
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain("Could not determine last modified timestamp for key [{$key}]. Skipping for safety.")
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_negative_or_future_last_modified_keeps_object(): void
    {
        $key = $this->putLogo(100);

        $this->mock(ClientLogoService::class, function ($mock) use ($key) {
            $mock->shouldReceive('allLogoKeys')->andReturn([$key]);
            $mock->shouldReceive('isR2Key')->with($key)->andReturn(true);
            $mock->shouldReceive('lastModified')->with($key)->andReturn(Carbon::now()->addHours(2)->getTimestamp());
            $mock->shouldNotReceive('safeDeleteKey');
        });

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain("Invalid last modified timestamp for key [{$key}]. Skipping for safety.")
            ->assertExitCode(0);

        Storage::disk('r2')->assertExists($key);
    }

    public function test_circuit_breaker_aborts_when_no_client_references_any_logo(): void
    {
        $orphan = $this->putLogo(100);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true])
            ->expectsOutputToContain('No client references any logo but logos exist in storage')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($orphan);
    }

    public function test_circuit_breaker_override_allows_empty_database_cleanup(): void
    {
        $orphan = $this->putLogo(100);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--allow-empty-references' => true])
            ->expectsOutputToContain('Destructive cleanup complete. Deleted: 1, Failed: 0, Skipped on revalidation: 0.')
            ->assertExitCode(0);

        Storage::disk('r2')->assertMissing($orphan);
    }

    public function test_circuit_breaker_aborts_when_eligible_exceeds_max_deletions(): void
    {
        $referenced = $this->putLogo(100);
        Client::factory()->create(['logo_path' => $referenced]);

        $o1 = $this->putLogo(100);
        $o2 = $this->putLogo(100);

        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--max-deletions' => 1])
            ->expectsOutputToContain('Eligible count (2) exceeds --max-deletions (1). Nothing was deleted')
            ->assertExitCode(1);

        Storage::disk('r2')->assertExists($o1);
        Storage::disk('r2')->assertExists($o2);
    }

    public function test_concurrent_cleanup_runs_are_locked_out(): void
    {
        Cache::lock(CleanOrphanedClientLogosCommand::LOCK_KEY, 3600)->get();

        $this->artisan('clients:clean-orphaned-logos')
            ->expectsOutputToContain('Another orphaned client logo cleanup is already running')
            ->assertExitCode(1);
    }

    public function test_logo_referenced_after_scan_is_skipped_by_revalidation(): void
    {
        $key = 'clients/logos/'.Str::uuid().'.jpg';
        $client = null;
        $this->mock(ClientLogoService::class, function ($mock) use ($key, &$client) {
            // The client starts referencing the key between the scan and the delete step
            $mock->shouldReceive('allLogoKeys')->andReturnUsing(function () use ($key, &$client) {
                $client = Client::factory()->create(['logo_path' => $key]);

                return [$key];
            });
            $mock->shouldReceive('isR2Key')->andReturn(true);
            $mock->shouldReceive('lastModified')->andReturn(Carbon::now()->subDays(3)->getTimestamp());
            $mock->shouldNotReceive('safeDeleteKey');
        });

        // allLogoKeys runs after the reference lookup, so the scan treats the key as unreferenced
        $this->artisan('clients:clean-orphaned-logos', ['--force' => true, '--allow-empty-references' => true])
            ->expectsOutputToContain('is now referenced in database')
            ->assertExitCode(0);
    }

    public function test_scheduled_command_is_registered_in_console_kernel(): void
    {
        $events = collect(app(Schedule::class)->events());

        $match = $events->first(function ($event) {
            return Str::contains($event->command ?? '', 'clients:clean-orphaned-logos');
        });

        expect($match)->not->toBeNull();
        expect($match->command)->toContain('--force')
            ->and($match->command)->toContain('--grace-hours=24')
            ->and($match->command)->toContain('--max-deletions=50');
    }
}
