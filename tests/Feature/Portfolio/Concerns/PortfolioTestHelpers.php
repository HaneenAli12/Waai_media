<?php

namespace Tests\Feature\Portfolio\Concerns;

use App\Models\Category;
use App\Models\Client;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use Database\Seeders\CategorySeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

trait PortfolioTestHelpers
{
    protected const FIXTURE_1X1_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    protected const FIXTURE_1X1_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected const ADMIN = '/api/v1/admin/portfolio';

    protected const PUBLIC = '/api/v1/portfolio';

    protected const JSON_HEADERS = ['Accept' => 'application/json'];

    protected function bootPortfolio(): void
    {
        $this->seed(CategorySeeder::class);
        Storage::fake('r2');
        Config::set('filesystems.disks.r2.url', 'https://pub-r2.waai.test');
    }

    protected function jpeg(string $name = 'work.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
    }

    protected function png(string $name = 'work.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::FIXTURE_1X1_PNG_BASE64));
    }

    protected function cat(string $slug): Category
    {
        return Category::where('type', 'portfolio')->where('slug', $slug)->firstOrFail();
    }

    /**
     * Base form fields for a (parent, subcategory) selection identified by the subcategory slug,
     * or by the parent slug for Web Design.
     *
     * @return array<string, mixed>
     */
    protected function selection(string $slug): array
    {
        $category = $this->cat($slug);

        if ($category->parent_id === null) {
            return ['category_id' => $category->id];
        }

        return ['category_id' => $category->parent_id, 'subcategory_id' => $category->id];
    }

    /**
     * selection() plus a fresh client (each call creates one, so repeated creations never share a category slot).
     *
     * @return array<string, mixed>
     */
    protected function sel(string $slug, ?int $clientId = null): array
    {
        return $this->selection($slug) + ['client_id' => $clientId ?? Client::factory()->create()->id];
    }

    /**
     * @return array<string, mixed>
     */
    protected function titles(string $en = 'Project', string $ar = 'مشروع'): array
    {
        return ['title_en' => $en, 'title_ar' => $ar];
    }

    /**
     * Seed a stored item directly in the database (and its fake R2 objects).
     *
     * @param  list<array{image?: bool, url?: string|null}>  $media
     */
    protected function makeItem(string $slug, array $media = [], array $attributes = [], bool $published = false): PortfolioItem
    {
        $selection = $this->selection($slug);

        $cover = 'portfolio/images/'.fake()->uuid().'.jpg';
        Storage::disk('r2')->put($cover, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));

        $item = PortfolioItem::factory()->state($attributes + [
            'cover_image_path' => $cover,
            'category_id' => $selection['category_id'],
            'subcategory_id' => $selection['subcategory_id'] ?? null,
        ])->when($published, fn ($f) => $f->published())->create();

        foreach ($media as $order => $entry) {
            $key = null;
            if ($entry['image'] ?? false) {
                $key = 'portfolio/images/'.fake()->uuid().'.jpg';
                Storage::disk('r2')->put($key, base64_decode(self::FIXTURE_1X1_JPEG_BASE64));
            }
            PortfolioMedia::create([
                'portfolio_item_id' => $item->id,
                'image_path' => $key,
                'url' => $entry['url'] ?? null,
                'sort_order' => $order,
            ]);
        }

        return $item->fresh();
    }

    /**
     * @return list<string>
     */
    protected function r2Files(): array
    {
        // Work images only; dedicated covers are listed by r2CoverFiles()
        $covers = PortfolioItem::query()->whereNotNull('cover_image_path')->pluck('cover_image_path')->all();

        return array_values(array_diff(Storage::disk('r2')->allFiles(), $covers));
    }

    /**
     * @return list<string>
     */
    protected function r2AllFiles(): array
    {
        return Storage::disk('r2')->allFiles();
    }

    /**
     * @return array<string, mixed>
     */
    protected function withCover(): array
    {
        return ['cover_image' => $this->jpeg('cover.jpg')];
    }
}
