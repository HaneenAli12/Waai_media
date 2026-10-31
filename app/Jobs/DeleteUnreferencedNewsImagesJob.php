<?php

namespace App\Jobs;

use App\Models\NewsImage;
use App\Services\News\NewsImageService;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeleteUnreferencedNewsImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Backoff intervals in seconds between retries.
     *
     * @var list<int>
     */
    public array $backoff = [10, 30, 60];

    /**
     * Number of seconds the unique job lock is held.
     */
    public int $uniqueFor = 300;

    /**
     * @param  list<int>  $imageIds
     */
    public function __construct(
        public readonly array $imageIds,
        public readonly ?int $ignoreNewsId = null
    ) {}

    /**
     * Unique ID for job deduplication.
     */
    public function uniqueId(): string
    {
        $ids = $this->imageIds;
        sort($ids);

        return 'news-img-cleanup:'.implode(',', $ids);
    }

    /**
     * Retry deadline.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /**
     * Execute the job.
     *
     * Rechecks all database references across all articles before attempting deletion;
     * if an image was reattached to any article after the job was queued, it is skipped safely.
     */
    public function handle(NewsImageService $imageService): void
    {
        $failedIds = [];

        foreach (array_values(array_unique(array_map('intval', $this->imageIds))) as $imageId) {
            if ($imageId <= 0) {
                continue;
            }

            // Recheck all references across all articles right before deleting; never ignore any existing article
            if ($imageService->isImageReferenced($imageId)) {
                continue;
            }

            $deleted = $imageService->deleteImageIfUnreferenced($imageId);
            if (! $deleted && NewsImage::where('id', $imageId)->exists() && ! $imageService->isImageReferenced($imageId)) {
                $failedIds[] = $imageId;
            }
        }

        if ($failedIds !== []) {
            Log::warning('Transient failure deleting unreferenced news images from R2; job will retry.', [
                'failed_image_ids' => $failedIds,
            ]);

            throw new RuntimeException('Transient R2 deletion failure for news image IDs: '.implode(', ', $failedIds));
        }
    }
}
