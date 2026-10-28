<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicClientIndexRequest;
use App\Http\Resources\ClientPublicResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    /**
     * Default page size for the public client listing.
     */
    public const PER_PAGE = 10;

    /**
     * Retrieve the public list of clients for Home Page with page-number pagination.
     *
     * Unauthenticated public endpoint. Returns clients ordered deterministically
     * (newest first, ties broken by id descending).
     */
    public function index(PublicClientIndexRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = Client::query()->orderedForAdmin()->paginate($perPage);

        return ClientPublicResource::collection($paginated)->response();
    }
}
