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
class PortfolioItemPublicResource extends JsonResource
{
    /**
     * Public representation, localized by Accept-Language. Only published items reach this resource.
     * Draft state, storage keys and internal timestamps are not exposed.
     *
     * Card fields are always present. The category-specific content (media, links) and the sibling navigation are
     * only included when the controller loaded them (details), so listings stay light for any number of images.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));
        $images = app(PortfolioImageService::class);

        $title = $locale === 'ar'
            ? ($this->title_ar ?: $this->title_en)
            : ($this->title_en ?: $this->title_ar);

        $data = [
            'id' => (int) $this->id,
            'title' => $title,
            'cover_image_url' => $images->resolveUrl($this->cover_image_path),
            'client' => PortfolioClientPresenter::present($this->relationLoaded('client') ? $this->client : null),
            'category' => PortfolioCategoryPresenter::present($this->category, $locale),
            'subcategory' => PortfolioCategoryPresenter::present($this->subcategory, $locale),
            'kind' => PortfolioKind::resolve($this->category, $this->subcategory),
            'published_at' => $this->published_at?->toISOString(),
        ];

        if ($this->relationLoaded('media')) {
            $data['media'] = $this->media->map(fn ($row) => [
                'id' => (int) $row->id,
                'image_url' => $images->resolveUrl($row->image_path),
                'url' => $row->url,
            ])->values()->all();
            $data['link_1'] = $this->link_1;
            $data['link_2'] = $this->link_2;
            $data['link_3'] = $this->link_3;
        }

        if ($this->relationLoaded('siblings')) {
            // Published items of the same client in the same main category (including this one), in tab order
            $data['client_category_items'] = $this->siblings->map(fn (PortfolioItem $sibling) => [
                'id' => (int) $sibling->id,
                'title' => $locale === 'ar'
                    ? ($sibling->title_ar ?: $sibling->title_en)
                    : ($sibling->title_en ?: $sibling->title_ar),
                'cover_image_url' => $images->resolveUrl($sibling->cover_image_path),
                'subcategory' => PortfolioCategoryPresenter::present($sibling->subcategory, $locale),
                'is_current' => $sibling->id === $this->id,
            ])->values()->all();
        }

        return $data;
    }
}
