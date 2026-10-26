<?php

namespace App\Console\Commands;

use App\Models\Music;
use App\Services\Storage\AudioStorageService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanOrphanedBackgroundMusicCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'music:clean-orphaned
                            {--force : Execute destructive cleanup (default is dry-run mode)}
                            {--grace-hours=24 : Minimum object age in hours before considering an object eligible for cleanup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean orphaned audio files from Cloudflare R2 background music storage.';

    /**
     * Execute the console command.
     */
    public function handle(AudioStorageService $audioStorage): int
    {
        $isForce = (bool) $this->option('force');
        $graceHours = max(1, (int) $this->option('grace-hours'));
        $cutoffTimestamp = Carbon::now()->subHours($graceHours)->getTimestamp();

        $modeText = $isForce ? 'DESTRUCTIVE' : 'DRY-RUN';
        $this->info("Starting orphaned background music cleanup [{$modeText}] (Grace period: {$graceHours}h).");

        // 1. Fail closed: Verify database connection and fetch referenced track keys
        $referencedKeys = [];
        try {
            DB::connection()->getPdo();

            // Fetch current active track key from music singleton
            $activeMusic = Music::find(1);
            if ($activeMusic && ! empty($activeMusic->file_path)) {
                $referencedKeys[] = $activeMusic->file_path;
            }

            // Also inspect any other rows if they ever exist
            $allDbMusicKeys = DB::table('music')
                ->whereNotNull('file_path')
                ->pluck('file_path')
                ->all();

            $referencedKeys = array_unique(array_merge($referencedKeys, $allDbMusicKeys));
        } catch (Throwable $e) {
            $this->error('Database health check or reference lookup failed. Aborting cleanup to fail closed.');
            Log::critical('Orphaned background music cleanup aborted: database lookup failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        // 2. Enumerate objects strictly under the dedicated audio prefix
        try {
            $allFiles = $audioStorage->allAudioFiles();
        } catch (Throwable $e) {
            $this->error('Failed to list audio files from storage disk. Aborting cleanup.');
            Log::critical('Orphaned background music cleanup aborted: storage listing failed.', [
                'error' => $e->getMessage(),
            ]);

            return Command::FAILURE;
        }

        $candidates = [];
        $skippedActive = 0;
        $skippedGrace = 0;
        $skippedInvalid = 0;

        foreach ($allFiles as $key) {
            // Check authorization of key prefix and pattern
            try {
                $audioStorage->validateStorageKey($key);
            } catch (Throwable) {
                $this->warn("Skipping key with unexpected format: {$key}");
                $skippedInvalid++;

                continue;
            }

            // Never delete active / referenced keys
            if (in_array($key, $referencedKeys, true)) {
                $skippedActive++;

                continue;
            }

            // Check object age (grace period safety)
            try {
                $lastModified = $audioStorage->lastModified($key);
                if ($lastModified > $cutoffTimestamp) {
                    $skippedGrace++;

                    continue;
                }
            } catch (Throwable $e) {
                $this->warn("Could not determine last modified timestamp for key [{$key}]. Skipping for safety.");
                $skippedGrace++;

                continue;
            }

            $candidates[] = $key;
        }

        $this->info(sprintf(
            'Scan complete. Total files: %d | Referenced/Active: %d | Within Grace Period: %d | Invalid/Skipped: %d | Orphan Candidates: %d',
            count($allFiles),
            $skippedActive,
            $skippedGrace,
            $skippedInvalid,
            count($candidates)
        ));

        if (empty($candidates)) {
            $this->info('No orphaned audio files found eligible for cleanup.');

            return Command::SUCCESS;
        }

        // If not force mode, perform Dry Run only
        if (! $isForce) {
            $this->warn('DRY-RUN MODE ENABLED: No files were deleted.');
            $this->table(['Candidate Orphaned Key', 'Status'], array_map(function ($key) {
                return [$key, 'Eligible for deletion (dry-run)'];
            }, $candidates));

            Log::info('Orphaned background music cleanup dry-run completed.', [
                'candidate_count' => count($candidates),
                'candidates' => $candidates,
                'grace_hours' => $graceHours,
            ]);

            return Command::SUCCESS;
        }

        // Destructive execution
        $deletedCount = 0;
        $failedCount = 0;

        foreach ($candidates as $key) {
            // JIT Revalidation: Re-verify against the active database state immediately before deleting
            try {
                $currentActiveTrack = DB::table('music')->where('id', 1)->value('file_path');
                if ($currentActiveTrack === $key) {
                    $this->warn("Candidate [{$key}] was activated concurrently. Skipping deletion.");
                    Log::warning('Candidate orphaned audio became active concurrently; deletion skipped.', [
                        'key' => $key,
                    ]);

                    continue;
                }

                $stillReferenced = DB::table('music')->where('file_path', $key)->exists();
                if ($stillReferenced) {
                    $this->warn("Candidate [{$key}] is now referenced in database. Skipping deletion.");

                    continue;
                }
            } catch (Throwable $e) {
                $this->error("Database revalidation failed for [{$key}]. Aborting cleanup for safety.");
                Log::critical('Database revalidation failed during destructive orphaned audio cleanup.', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);

                return Command::FAILURE;
            }

            $success = $audioStorage->safeDelete($key);
            if ($success) {
                $deletedCount++;
                Log::info("Orphaned background music object deleted: {$key}");
            } else {
                $failedCount++;
                Log::warning("Failed to delete orphaned background music object: {$key}");
            }
        }

        $this->info("Destructive cleanup complete. Deleted: {$deletedCount}, Failed: {$failedCount}.");

        return $failedCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
