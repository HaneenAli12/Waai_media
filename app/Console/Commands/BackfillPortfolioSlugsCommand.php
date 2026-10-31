<?php

namespace App\Console\Commands;

use App\Models\PortfolioItem;
use App\Services\Portfolio\PortfolioSlugService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillPortfolioSlugsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'portfolio:backfill-slugs
                            {--dry-run : Simulate the backfill without writing slugs to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill missing slugs for existing portfolio items using PortfolioSlugService.';

    /**
     * Execute the console command.
     */
    public function handle(PortfolioSlugService $slugService): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $query = PortfolioItem::query()
            ->where(function ($q) {
                $q->whereNull('slug')->orWhere('slug', '');
            })
            ->orderBy('id');

        $total = $query->count();

        if ($total === 0) {
            $this->info('No portfolio items require a slug backfill.');

            return self::SUCCESS;
        }

        $this->info("Found {$total} portfolio item(s) without a valid slug.");

        if ($isDryRun) {
            $this->warn('DRY RUN mode enabled. No changes will be persisted to the database.');
        }

        $processed = 0;
        $skipped = 0;

        $query->chunk(100, function ($items) use ($slugService, $isDryRun, &$processed, &$skipped) {
            foreach ($items as $item) {
                /** @var PortfolioItem $item */
                $titleEn = $item->title_en;
                if ($titleEn === null || trim($titleEn) === '' || $slugService->normalizeTitle($titleEn) === null) {
                    $titleEn = 'portfolio';
                }

                $slug = $slugService->generateSlug($titleEn, $item->id);

                if ($slug === null) {
                    $this->warn("Item #{$item->id}: could not generate slug, skipping.");
                    $skipped++;

                    continue;
                }

                $this->line("Item #{$item->id} -> slug: {$slug}");

                if (! $isDryRun) {
                    DB::table('portfolio_items')
                        ->where('id', $item->id)
                        ->update(['slug' => $slug]);
                }

                $processed++;
            }
        });

        if ($isDryRun) {
            $this->info("Dry run complete: {$processed} item(s) would be updated, {$skipped} skipped.");
        } else {
            $this->info("Backfill complete: {$processed} item(s) successfully updated, {$skipped} skipped.");
        }

        return self::SUCCESS;
    }
}

