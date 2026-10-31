<?php

namespace App\Http\Resources;

use App\Models\PortfolioItem;
use App\Services\Portfolio\PortfolioImageService;
use App\Support\LocaleNegotiator;
use App\Support\Portfolio\PortfolioCategoryPresenter;
use App\Support\Portfolio\PortfolioClientPresenter;
use App\Support\Portfolio\PortfolioKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PortfolioItem
 */
class PortfolioItemAdminResource extends JsonResource
{
    /**
     * Admin representation: both languages for editing.
     * Storage keys are never exposed, only resolved public URLs.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));
        $images = app(PortfolioImageService::class);

        $kind = PortfolioKind::resolve($this->category, $this->subcategory);

        $data = [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'title_en' => $this->title_en,
            'title_ar' => $this->title_ar,
            'status' => (string) $this->status,
            'client_id' => $this->client_id === null ? null : (int) $this->client_id,
            'client' => PortfolioClientPresenter::present($this->relationLoaded('client') ? $this->client : null),
            'cover_image_url' => $images->resolveUrl($this->cover_image_path),
            'category' => PortfolioCategoryPresenter::present($this->relationLoaded('category') ? $this->category : null, $locale),
            'subcategory' => PortfolioCategoryPresenter::present($this->relationLoaded('subcategory') ? $this->subcategory : null, $locale),
            'kind' => $kind,
            'published_at' => $this->published_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];

        if ($kind === PortfolioKind::WEB_DESIGN) {
            $data['link_1'] = $this->link_1;
            $data['link_2'] = $this->link_2;
            $data['link_3'] = $this->link_3;
        }

        if ($this->relationLoaded('media')) {
            $data['media_count'] = $this->media->count();
            $data['media'] = $this->media->map(fn ($row) => [
                'id' => (int) $row->id,
                'image_url' => $images->resolveUrl($row->image_path),
                'url' => $row->url,
                'sort_order' => (int) $row->sort_order,
            ])->values()->all();
        } elseif (isset($this->media_count)) {
            $data['media_count'] = (int) $this->media_count;
        }

        return $data;
    }
}
