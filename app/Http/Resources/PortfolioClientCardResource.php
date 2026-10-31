<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Support\Portfolio\PortfolioClientPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A client card of the Client-based browsing view: the Clients module's public fields plus the selected main
 * category and how many published items the client has in it. The controller sets `portfolio_category` and
 * `published_items_count` on each client.
 *
 * @mixin Client
 */
class PortfolioClientCardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return PortfolioClientPresenter::present($this->resource) + [
            'category' => $this->resource->getAttribute('portfolio_category'),
            'published_items_count' => (int) $this->resource->getAttribute('published_items_count'),
        ];
    }
}
