<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CounterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns only the stable identifier, English label, numerical value, and display order.
     * Sensitive internal database metadata (timestamps, etc.) is strictly omitted.
     *
     * @return array<string, int|string>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'key' => (string) $this->key,
            'name' => (string) $this->name,
            'value' => (int) $this->value,
            'order' => (int) $this->order,
        ];
    }
}
