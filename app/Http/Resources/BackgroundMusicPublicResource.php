<?php

namespace App\Http\Resources;

use App\Services\Storage\AudioStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackgroundMusicPublicResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AudioStorageService $storageService */
        $storageService = app(AudioStorageService::class);

        $audioUrl = $this->file_path ? $storageService->getUrl($this->file_path) : null;

        return [
            'is_enabled' => (bool) ($this->is_enabled ?? false),
            'is_loop' => (bool) ($this->is_loop ?? false),
            'audio_url' => $audioUrl,
        ];
    }
}
