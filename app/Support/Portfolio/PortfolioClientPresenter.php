<?php

namespace App\Support\Portfolio;

use App\Models\Client;
use App\Services\Clients\ClientLogoService;

final class PortfolioClientPresenter
{
    /**
     * Compact client representation reusing the Clients module's approved public fields (id, name, logo_url).
     *
     * @return array{id: int, name: string, logo_url: string|null}|null
     */
    public static function present(?Client $client): ?array
    {
        if ($client === null) {
            return null;
        }

        return [
            'id' => (int) $client->id,
            'name' => (string) $client->name,
            'logo_url' => app(ClientLogoService::class)->resolveUrl($client->logo_path),
        ];
    }
}
