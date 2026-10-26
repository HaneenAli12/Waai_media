<?php

namespace App\Http\Resources;

use App\Services\Storage\AudioStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BackgroundMusicAdminResource extends JsonResource
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

        $fileUrl = $this->file_path ? $storageService->getUrl($this->file_path) : null;

        return [
            'id' => $this->id,
            'has_track' => ! empty($this->file_path),
            'file_name' => $this->file_name,
            'file_url' => $fileUrl,
            'file_size' => $this->file_size ? (int) $this->file_size : null,
            'mime_type' => $this->mime_type,
            'is_enabled' => (bool) ($this->is_enabled ?? false),
            'is_loop' => (bool) ($this->is_loop ?? false),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
