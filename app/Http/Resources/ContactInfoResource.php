<?php

namespace App\Http\Resources;

use App\Models\ContactInfo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ContactInfo
 */
class ContactInfoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns strictly the 12 agreed contact information fields,
     * omitting internal database identifiers, locks, and timestamps.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'phone_1' => $this->phone_1,
            'phone_2' => $this->phone_2,
            'phone_3' => $this->phone_3,
            'official_email' => $this->official_email,
            'facebook' => $this->facebook,
            'instagram' => $this->instagram,
            'youtube' => $this->youtube,
            'linkedin' => $this->linkedin,
            'tiktok' => $this->tiktok,
            'whatsapp' => $this->whatsapp,
            'complete_address' => $this->complete_address,
            'google_maps_url' => $this->google_maps_url,
        ];
    }
}
