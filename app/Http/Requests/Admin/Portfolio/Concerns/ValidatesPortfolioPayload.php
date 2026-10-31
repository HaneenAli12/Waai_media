<?php

namespace App\Http\Requests\Admin\Portfolio\Concerns;

use App\Models\Category;
use App\Models\PortfolioItem;
use App\Services\Portfolio\PortfolioImageService;
use App\Support\Portfolio\PortfolioKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validation for creating/updating portfolio items.
 *
 * Syntactic rules live in portfolioRules(); the category-dependent rules (a subcategory must belong to its parent,
 * each content kind accepts only its own inputs) run in validatePortfolioPayload(), so a client cannot bypass the
 * category-specific requirements by sending the wrong inputs.
 */
trait ValidatesPortfolioPayload
{
    /**
     * Request keys an update may change.
     *
     * @var list<string>
     */
    public const UPDATABLE_FIELDS = [
        'title_en', 'title_ar', 'client_id', 'category_id', 'subcategory_id', 'status', 'cover_image',
        'link_1', 'link_2', 'link_3', 'featured_image', 'media', 'remove_media_ids',
    ];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function portfolioRules(): array
    {
        $image = ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:'.(PortfolioImageService::MAX_SIZE_BYTES / 1024)];
        $link = ['nullable', 'string', 'url:http,https', 'max:500'];

        return [
            'title_en' => ['nullable', 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'client_id' => ['integer', Rule::exists('clients', 'id')],
            'subcategory_id' => ['nullable', 'integer'],
            'cover_image' => $image,
            'status' => ['nullable', 'string', 'in:draft,published'],
            'link_1' => $link,
            'link_2' => $link,
            'link_3' => $link,
            'featured_image' => $image,
            'media' => ['nullable', 'array'],
            'media.*' => ['array'],
            'media.*.id' => ['nullable', 'integer'],
            'media.*.image' => $image,
            'media.*.url' => $link,
            'remove_media_ids' => ['nullable', 'array'],
            'remove_media_ids.*' => ['integer'],
            'confirm_media_reset' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function portfolioMessages(): array
    {
        return [
            'client_id.exists' => 'The selected client does not exist.',
            'status.in' => 'The status must be either draft or published.',
            'link_1.url' => 'The first project link must be a valid http(s) URL.',
            'link_2.url' => 'The second project link must be a valid http(s) URL.',
            'link_3.url' => 'The third project link must be a valid http(s) URL.',
            'media.*.url.url' => 'Each URL must be a valid http(s) URL.',
            '*.image' => 'Each image must be a valid PNG or JPG file.',
            '*.mimes' => 'Images must be PNG or JPG files.',
            '*.max' => 'The file must not exceed 10 MB.',
        ];
    }

    /**
     * Category-dependent validation. $existing is the item being updated (null on create).
     */
    protected function validatePortfolioPayload(Validator $v, ?PortfolioItem $existing): void
    {
        if ($v->errors()->isNotEmpty()) {
            return;
        }

        $categoryId = $this->has('category_id') ? $this->input('category_id') : $existing?->category_id;
        $subId = $this->has('subcategory_id') ? $this->input('subcategory_id') : $existing?->subcategory_id;

        $parent = $categoryId !== null
            ? Category::where('type', 'portfolio')->whereNull('parent_id')->find((int) $categoryId)
            : null;

        if ($parent === null) {
            $v->errors()->add('category_id', 'The selected portfolio category is invalid.');

            return;
        }

        // A subcategory is required whenever the main category has subcategories - for drafts too
        $sub = null;
        $hasChildren = $parent->children()->exists();
        if ($subId !== null) {
            if (! $hasChildren) {
                $v->errors()->add('subcategory_id', 'The selected category does not have subcategories.');

                return;
            }

            $sub = Category::where('type', 'portfolio')->where('parent_id', $parent->id)->find((int) $subId);
            if ($sub === null) {
                $v->errors()->add('subcategory_id', 'The selected subcategory does not belong to the selected category.');

                return;
            }
        }

        if ($hasChildren && $sub === null) {
            $v->errors()->add('subcategory_id', 'A subcategory is required for the selected category.');

            return;
        }

        $kind = PortfolioKind::resolve($parent, $sub);
        if ($kind === null) {
            $v->errors()->add('subcategory_id', 'The selected category/subcategory combination is not supported.');

            return;
        }

        $clientId = $this->has('client_id') ? $this->input('client_id') : $existing?->client_id;
        if ($clientId === null) {
            $v->errors()->add('client_id', 'The client is required.');

            return;
        }

        $this->validateSlotIsFree($v, $existing, (int) $clientId, $parent, $sub);
        $this->validateKindInputs($v, $kind, $existing);
        $this->validateTitles($v, $existing);
    }

    /**
     * One client can hold at most one item (draft or published) per category slot: (client, subcategory) for
     * categories with subcategories, (client, category) otherwise. The database unique index is the final guard.
     */
    private function validateSlotIsFree(Validator $v, ?PortfolioItem $existing, int $clientId, Category $parent, ?Category $sub): void
    {
        $taken = PortfolioItem::query()
            ->where('client_id', $clientId)
            ->where('category_id', $parent->id)
            ->when($sub !== null, fn ($q) => $q->where('subcategory_id', $sub->id), fn ($q) => $q->whereNull('subcategory_id'))
            ->when($existing !== null, fn ($q) => $q->where('id', '!=', $existing->id))
            ->value('id');

        if ($taken === null) {
            return;
        }

        if ($sub !== null) {
            $v->errors()->add('subcategory_id', sprintf('This client already has a portfolio item (id %d) in this subcategory. Update it instead of creating another.', $taken));
        } else {
            $v->errors()->add('category_id', sprintf('This client already has a portfolio item (id %d) in this category. Update it instead of creating another.', $taken));
        }
    }

    private function validateKindInputs(Validator $v, string $kind, ?PortfolioItem $existing): void
    {
        $entries = $this->all()['media'] ?? null;
        $entries = is_array($entries) ? $entries : [];
        $removeIds = array_map('intval', (array) ($this->input('remove_media_ids') ?? []));

        if ($kind === PortfolioKind::WEB_DESIGN) {
            if ($entries !== [] || $removeIds !== []) {
                $v->errors()->add('media', 'Web Design does not accept media entries. Upload the featured_image instead.');
            }
        } else {
            foreach (['link_1', 'link_2', 'link_3'] as $link) {
                if ($this->filled($link)) {
                    $v->errors()->add($link, 'Project links are only available for Web Design.');
                }
            }
            if ($this->hasFile('featured_image') || $this->filled('featured_image')) {
                $v->errors()->add('featured_image', 'A featured image is only available for Web Design.');
            }
        }

        $ownIds = $existing ? $existing->media()->pluck('id')->map(fn ($id) => (int) $id)->all() : [];

        foreach ($removeIds as $id) {
            if (! in_array($id, $ownIds, true)) {
                $v->errors()->add('remove_media_ids', "Media entry {$id} does not belong to this portfolio item.");
            }
        }

        foreach ($entries as $i => $entry) {
            $id = $entry['id'] ?? null;
            $hasFile = ($entry['image'] ?? null) instanceof UploadedFile;
            $hasUrlKey = array_key_exists('url', $entry);
            $url = $entry['url'] ?? null;

            if ($id !== null && ! in_array((int) $id, $ownIds, true)) {
                $v->errors()->add("media.{$i}.id", 'The media entry does not belong to this portfolio item.');

                continue;
            }

            if ($kind === PortfolioKind::IMAGES) {
                if (filled($url)) {
                    $v->errors()->add("media.{$i}.url", 'This subcategory does not accept URLs for its images.');
                }
                if ($id === null && ! $hasFile) {
                    $v->errors()->add("media.{$i}.image", 'An image file is required for each new media entry.');
                }
            } elseif ($kind === PortfolioKind::IMAGES_WITH_URL) {
                if ($id === null && ! $hasFile) {
                    $v->errors()->add("media.{$i}.image", 'An image file is required for each new media entry.');
                }
                // Every newly added image needs its own URL; an existing pair keeps its URL (it can be changed, not cleared)
                if (($id === null || $hasUrlKey) && blank($url)) {
                    $v->errors()->add("media.{$i}.url", 'Each Press Covering image requires its own URL.');
                }
            } elseif ($kind === PortfolioKind::VIDEO_URLS) {
                if ($hasFile || isset($entry['image'])) {
                    $v->errors()->add("media.{$i}.image", 'Video subcategories accept video URLs only; files are not accepted.');
                }
                if (($id === null || $hasUrlKey) && blank($url)) {
                    $v->errors()->add("media.{$i}.url", 'A video URL is required for each video entry.');
                }
            }
        }
    }

    private function validateTitles(Validator $v, ?PortfolioItem $existing): void
    {
        $en = $this->has('title_en') ? $this->input('title_en') : $existing?->title_en;
        $ar = $this->has('title_ar') ? $this->input('title_ar') : $existing?->title_ar;

        if (trim((string) $en) === '' && trim((string) $ar) === '') {
            $v->errors()->add('title_en', 'At least one project title (English or Arabic) is required.');
        }
    }

    /**
     * Normalised input for PortfolioItemService::save().
     *
     * @return array<string, mixed>
     */
    protected function portfolioServiceInput(): array
    {
        $fields = [];
        foreach (['title_en', 'title_ar', 'link_1', 'link_2', 'link_3'] as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $fields[$key] = is_string($value) ? trim($value) : $value;
            }
        }
        foreach (['client_id', 'category_id', 'subcategory_id'] as $key) {
            if ($this->has($key)) {
                $fields[$key] = $this->input($key) === null ? null : (int) $this->input($key);
            }
        }

        $entries = [];
        $media = $this->all()['media'] ?? null;
        foreach (is_array($media) ? $media : [] as $i => $entry) {
            $file = $entry['image'] ?? null;
            $entries[] = [
                'index' => $i,
                'id' => isset($entry['id']) ? (int) $entry['id'] : null,
                'has_url' => array_key_exists('url', $entry),
                'url' => $entry['url'] ?? null,
                'file' => $file instanceof UploadedFile ? $file : null,
            ];
        }

        $featured = $this->file('featured_image');
        $cover = $this->file('cover_image');

        return [
            'fields' => $fields,
            'status' => $this->input('status'),
            'media' => $entries,
            'featured_image' => $featured instanceof UploadedFile ? $featured : null,
            'cover_image' => $cover instanceof UploadedFile ? $cover : null,
            'remove_media_ids' => array_map('intval', (array) ($this->input('remove_media_ids') ?? [])),
            'confirm_media_reset' => $this->boolean('confirm_media_reset'),
        ];
    }

    protected function isPortfolioAuthor(): bool
    {
        $user = $this->user();

        return $user !== null && in_array($user->role, ['super_admin', 'admin', 'editor'], true);
    }
}
