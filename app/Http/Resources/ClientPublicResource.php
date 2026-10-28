<?php

namespace App\Http\Resources;

use App\Models\Client;
use App\Services\Clients\ClientLogoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Client
 */
class ClientPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'logo_url' => app(ClientLogoService::class)->resolveUrl($this->logo_path),
        ];
    }
}
