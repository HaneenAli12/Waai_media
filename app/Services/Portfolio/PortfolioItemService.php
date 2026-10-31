<?php

namespace App\Services\Portfolio;

use App\Models\Category;
use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Services\Storage\Exceptions\InvalidImageException;
use App\Support\Portfolio\PortfolioKind;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates, publishes and deletes portfolio items together with their media.
 *
 * Storage lifecycle (same guarantees as the Team/Clients/Events modules):
 *  - new files are uploaded to R2 before the transaction; on any failure they are removed again;
 *  - the item row (and its media) is read under a row lock inside the transaction;
 *  - superseded/removed images are deleted only after the database commit, and only when no other media
 *    entry references them; storage failures there are logged and never fail the request.
 */
class PortfolioItemService
{
    /**
     * BUSINESS DECISION (to be confirmed): publishing requires BOTH the Arabic and English title, matching the
     * Events module. Set to false to require only one of the two. Drafts only need one title (see requests).
     */
    public const REQUIRE_BOTH_LANGUAGES_TO_PUBLISH = true;

    public const SLOT_INDEX = 'uniq_portfolio_client_slot';

    public const SLOT_TAKEN_MESSAGE = 'This client already has a portfolio item in the selected category/subcategory. Update the existing item instead of creating another.';

    public function __construct(
        protected PortfolioImageService $images
    ) {}

    /**
     * Create (when $item is null) or update an item.
     *
     * @param  array{
     *     fields?: array<string, mixed>,
     *     status?: string|null,
     *     media?: list<array{id?: int|null, has_url?: bool, url?: string|null, file?: UploadedFile|null, index: int|string}>,
     *     featured_image?: UploadedFile|null,
     *     cover_image?: UploadedFile|null,
     *     remove_media_ids?: list<int>,
     *     confirm_media_reset?: bool
     * }  $input
     */
    public function save(?PortfolioItem $item, array $input): PortfolioItem
    {
        $entries = $input['media'] ?? [];
        $featuredFile = $input['featured_image'] ?? null;
        $coverFile = $input['cover_image'] ?? null;

        // 1. Upload new files first. Nothing in the database references them yet.
        $uploaded = [];
        try {
            foreach ($entries as $i => $entry) {
                if (($entry['file'] ?? null) instanceof UploadedFile) {
                    $key = $this->uploadFile($entry['file'], "media.{$entry['index']}.image");
                    $uploaded[] = $key;
                    $entries[$i]['key'] = $key;
                }
            }

            $featuredKey = null;
            if ($featuredFile instanceof UploadedFile) {
                $featuredKey = $this->uploadFile($featuredFile, 'featured_image');
                $uploaded[] = $featuredKey;
            }

            $coverKey = null;
            if ($coverFile instanceof UploadedFile) {
                $coverKey = $this->uploadFile($coverFile, 'cover_image');
                $uploaded[] = $coverKey;
            }
        } catch (\Throwable $e) {
            $this->discardUploads($uploaded);

            throw $e;
        }

        // 2. Persist atomically; remember images that become unreferenced.
        $keysToDelete = [];

        try {
            $saved = DB::transaction(function () use ($item, $input, $entries, $featuredKey, $coverKey, &$keysToDelete) {
                return $this->persist($item, $input, $entries, $featuredKey, $coverKey, $keysToDelete);
            });
        } catch (\Throwable $e) {
            $this->discardUploads($uploaded);

            // The unique index is the final guarantee against two items in the same client/category slot
            // (a concurrent request can pass the application check); report it like the validation error.
            if ($e instanceof UniqueConstraintViolationException && str_contains($e->getMessage(), self::SLOT_INDEX)) {
                throw ValidationException::withMessages([
                    'subcategory_id' => [self::SLOT_TAKEN_MESSAGE],
                ]);
            }

            throw $e;
        }

        // 3. Only after a confirmed commit: remove superseded objects (guarded by a reference check).
        $this->cleanupKeys($keysToDelete);

        return $saved->load(['client', 'category', 'subcategory', 'media']);
    }

    /**
     * Move an item to published or draft without changing its content.
     */
    public function setStatus(PortfolioItem $item, string $status): PortfolioItem
    {
        return $this->save($item, ['status' => $status]);
    }

    /**
     * Permanently delete an item and its media rows (no soft deletes). R2 objects are removed after commit and
     * only when no other media entry still references them.
     */
    public function delete(PortfolioItem $item): void
    {
        $keys = [];

        DB::transaction(function () use ($item, &$keys) {
            $locked = PortfolioItem::whereKey($item->id)->lockForUpdate()->first();
            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(PortfolioItem::class, [$item->id]);
            }

            $keys = $locked->media()->lockForUpdate()->pluck('image_path')->filter()->all();
            if ($locked->cover_image_path) {
                $keys[] = $locked->cover_image_path;
            }
            $locked->delete(); // media rows are removed by the foreign key cascade
        });

        $this->cleanupKeys($keys);
    }

    /**
     * Delete every item of a client (media rows go with them through the foreign key cascade). Must be called inside
     * the caller's transaction - it only touches the database and returns the R2 keys (covers and work images) to
     * pass to cleanupKeys() AFTER the transaction has committed.
     *
     * @return list<string>
     */
    public function deleteAllForClient(int $clientId): array
    {
        $items = PortfolioItem::where('client_id', $clientId)->lockForUpdate()->get(['id', 'cover_image_path']);
        if ($items->isEmpty()) {
            return [];
        }

        $keys = PortfolioMedia::whereIn('portfolio_item_id', $items->pluck('id'))->lockForUpdate()->pluck('image_path')->filter()->all();
        foreach ($items as $item) {
            if ($item->cover_image_path) {
                $keys[] = $item->cover_image_path;
            }
        }

        PortfolioItem::whereIn('id', $items->pluck('id'))->delete();

        return array_values(array_unique($keys));
    }

    /**
     * Remove R2 objects that became unreferenced, after the database commit. A key that another cover or media row
     * still references is kept. Failures are logged and never thrown: the database change has already committed and
     * the weekly portfolio:clean-orphaned-images run removes whatever is left.
     *
     * @param  list<string>  $keys
     */
    public function cleanupKeys(array $keys): void
    {
        foreach (array_unique($keys) as $key) {
            try {
                $this->images->deleteIfUnshared($key);
            } catch (\Throwable $e) {
                Log::warning(sprintf('Portfolio image cleanup failed for %s and is left for the orphan cleanup: %s', $key, $e->getMessage()));
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $keysToDelete
     */
    private function persist(?PortfolioItem $item, array $input, array $entries, ?string $featuredKey, ?string $coverKey, array &$keysToDelete): PortfolioItem
    {
        $fields = $input['fields'] ?? [];
        $isNew = $item === null;

        if ($isNew) {
            $model = new PortfolioItem;
            $model->status = PortfolioItem::STATUS_DRAFT;
        } else {
            $model = PortfolioItem::whereKey($item->id)->lockForUpdate()->first();
            if ($model === null) {
                throw (new ModelNotFoundException)->setModel(PortfolioItem::class, [$item->id]);
            }
        }

        $originalKind = $isNew ? null : $this->kindFor($model->category_id, $model->subcategory_id);
        $hadStoredLinks = ! $isNew && ($model->link_1 || $model->link_2 || $model->link_3);

        $model->fill($fields);

        if (! $this->selectionIsValid($model->category_id, $model->subcategory_id)) {
            throw ValidationException::withMessages([
                'subcategory_id' => ['The selected category/subcategory combination is not supported.'],
            ]);
        }

        $kind = $this->kindFor($model->category_id, $model->subcategory_id);
        if ($kind === null) {
            // Every state (draft or published) needs a complete category assignment. This also stops stored drafts
            // from before this rule from being saved, published or unpublished without choosing a subcategory.
            throw ValidationException::withMessages([
                'subcategory_id' => ['A subcategory is required for the selected category.'],
            ]);
        }

        /** @var Collection<int, PortfolioMedia> $existing */
        $existing = $isNew ? collect() : $model->media()->lockForUpdate()->get()->keyBy('id');

        // Category change to a different content kind: stored media/links no longer apply. Never drop them silently.
        if ($originalKind !== null && $originalKind !== $kind && ($existing->isNotEmpty() || $hadStoredLinks)) {
            if (! ($input['confirm_media_reset'] ?? false)) {
                throw ValidationException::withMessages([
                    'confirm_media_reset' => [sprintf(
                        'Changing to this category would remove %d existing media entr%s and any stored project links. '
                        .'Resend the request with confirm_media_reset=true to confirm.',
                        $existing->count(),
                        $existing->count() === 1 ? 'y' : 'ies'
                    )],
                ]);
            }

            foreach ($existing as $row) {
                if ($row->image_path) {
                    $keysToDelete[] = $row->image_path;
                }
                $row->delete();
            }
            $existing = collect();

            foreach (['link_1', 'link_2', 'link_3'] as $link) {
                $model->{$link} = $fields[$link] ?? null;
            }
        }

        // Dedicated cover: replaced only when a new file arrives; the old object is removed after commit
        if ($coverKey !== null) {
            if ($model->cover_image_path) {
                $keysToDelete[] = $model->cover_image_path;
            }
            $model->cover_image_path = $coverKey;
        }

        $model->save();

        $this->applyMedia($model, $kind, $existing, $input, $entries, $featuredKey, $keysToDelete);

        // Status: create defaults to draft, update keeps the current status unless one is requested.
        $target = $input['status'] ?? $model->status;
        $media = $model->media()->get();

        if ($target === PortfolioItem::STATUS_PUBLISHED) {
            $errors = $this->publishErrors($model, $kind, $media);
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $model->status = PortfolioItem::STATUS_PUBLISHED;
            $model->published_at ??= now();
        } else {
            $model->status = PortfolioItem::STATUS_DRAFT;
            $model->published_at = null;
        }

        $model->save();

        return $model;
    }

    /**
     * @param  Collection<int, PortfolioMedia>  $existing
     * @param  list<array<string, mixed>>  $entries
     * @param  list<string>  $keysToDelete
     */
    private function applyMedia(PortfolioItem $model, string $kind, Collection $existing, array $input, array $entries, ?string $featuredKey, array &$keysToDelete): void
    {
        if ($kind === PortfolioKind::WEB_DESIGN) {
            if ($featuredKey !== null) {
                $row = $existing->first();
                if ($row !== null) {
                    if ($row->image_path) {
                        $keysToDelete[] = $row->image_path;
                    }
                    $row->image_path = $featuredKey;
                    $row->save();
                } else {
                    $model->media()->create(['image_path' => $featuredKey, 'url' => null, 'sort_order' => 0]);
                }
            }

            return;
        }

        $removeIds = array_map('intval', $input['remove_media_ids'] ?? []);
        foreach ($removeIds as $id) {
            $row = $existing->get($id);
            if ($row === null) {
                continue;
            }
            if ($row->image_path) {
                $keysToDelete[] = $row->image_path;
            }
            $row->delete();
            $existing->forget($id);
        }

        $nextOrder = (int) ($existing->max('sort_order') ?? -1) + 1;

        foreach ($entries as $entry) {
            $id = $entry['id'] ?? null;
            $key = $entry['key'] ?? null;

            if ($id !== null) {
                $row = $existing->get((int) $id);
                if ($row === null) {
                    throw ValidationException::withMessages([
                        "media.{$entry['index']}.id" => ['The media entry does not belong to this portfolio item.'],
                    ]);
                }
                if ($key !== null) {
                    if ($row->image_path) {
                        $keysToDelete[] = $row->image_path;
                    }
                    $row->image_path = $key;
                }
                if (! empty($entry['has_url'])) {
                    $row->url = $entry['url'] ?? null;
                }
                $row->save();

                continue;
            }

            $model->media()->create([
                'image_path' => $key,
                'url' => $entry['url'] ?? null,
                'sort_order' => $nextOrder++,
            ]);
        }
    }

    /**
     * Publish readiness for the stored state. Drafts are never checked here.
     *
     * @param  Collection<int, PortfolioMedia>  $media
     * @return array<string, list<string>>
     */
    private function publishErrors(PortfolioItem $model, string $kind, Collection $media): array
    {
        $errors = [];

        if ($model->client_id === null || ! Client::whereKey($model->client_id)->exists()) {
            $errors['client_id'][] = 'A valid client is required to publish.';
        }

        if (! $this->images->isR2Key($model->cover_image_path)) {
            $errors['cover_image'][] = 'A cover image is required to publish.';
        }

        $hasEn = trim((string) $model->title_en) !== '';
        $hasAr = trim((string) $model->title_ar) !== '';

        if (self::REQUIRE_BOTH_LANGUAGES_TO_PUBLISH) {
            if (! $hasEn) {
                $errors['title_en'][] = 'The English project title is required to publish.';
            }
            if (! $hasAr) {
                $errors['title_ar'][] = 'The Arabic project title is required to publish.';
            }
        } elseif (! $hasEn && ! $hasAr) {
            $errors['title_en'][] = 'At least one project title (English or Arabic) is required to publish.';
        }

        switch ($kind) {
            case PortfolioKind::IMAGES:
                if ($media->whereNotNull('image_path')->isEmpty()) {
                    $errors['media'][] = 'At least one image is required to publish.';
                }
                break;

            case PortfolioKind::IMAGES_WITH_URL:
                if ($media->whereNotNull('image_path')->isEmpty()) {
                    $errors['media'][] = 'At least one image is required to publish.';
                }
                foreach ($media->values() as $position => $row) {
                    if (trim((string) $row->url) === '') {
                        $errors["media.{$position}.url"][] = "Every image requires its own URL to publish (media id {$row->id}).";
                    }
                }
                break;

            case PortfolioKind::VIDEO_URLS:
                if ($media->filter(fn ($row) => trim((string) $row->url) !== '')->isEmpty()) {
                    $errors['media'][] = 'At least one video URL is required to publish.';
                }
                break;

            case PortfolioKind::WEB_DESIGN:
                if ($media->whereNotNull('image_path')->isEmpty()) {
                    $errors['featured_image'][] = 'A featured image is required to publish.';
                }
                if (trim((string) $model->link_1) === '') {
                    $errors['link_1'][] = 'The first project link is required to publish.';
                }
                if (trim((string) $model->link_2) === '') {
                    $errors['link_2'][] = 'The second project link is required to publish.';
                }
                break;
        }

        return $errors;
    }

    /**
     * The parent must be a portfolio main category; a subcategory (if any) must be one of its children.
     */
    private function selectionIsValid(?int $categoryId, ?int $subcategoryId): bool
    {
        if ($categoryId === null || ! Category::where('type', 'portfolio')->whereNull('parent_id')->whereKey($categoryId)->exists()) {
            return false;
        }

        return $subcategoryId === null
            || Category::where('type', 'portfolio')->where('parent_id', $categoryId)->whereKey($subcategoryId)->exists();
    }

    private function kindFor(?int $categoryId, ?int $subcategoryId): ?string
    {
        if ($categoryId === null) {
            return null;
        }

        $parent = Category::where('type', 'portfolio')->whereNull('parent_id')->find($categoryId);
        $sub = $subcategoryId !== null
            ? Category::where('type', 'portfolio')->where('parent_id', $categoryId)->find($subcategoryId)
            : null;

        if ($subcategoryId !== null && $sub === null) {
            return null;
        }

        return PortfolioKind::resolve($parent, $sub);
    }

    private function uploadFile(UploadedFile $file, string $field): string
    {
        try {
            return $this->images->upload($file);
        } catch (InvalidImageException $e) {
            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function discardUploads(array $keys): void
    {
        foreach ($keys as $key) {
            // Reference-checked: if an ambiguous commit actually persisted the key, it is kept.
            $this->images->deleteIfUnshared($key);
        }
    }
}
