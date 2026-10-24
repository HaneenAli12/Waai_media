<?php

namespace App\Http\Resources;

use App\Models\AppLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AppLink
 */
class AppLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns strictly the three mobile app download link fields,
     * omitting internal database identifiers, locks, and timestamps.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'google_app_link' => $this->google_app_link,
            'apple_app_store_link' => $this->apple_app_store_link,
            'app_gallery_link' => $this->app_gallery_link,
        ];
    }
}
