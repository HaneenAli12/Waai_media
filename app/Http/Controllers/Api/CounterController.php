<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CounterResource;
use App\Models\Counter;
use Illuminate\Http\JsonResponse;

class CounterController extends Controller
{
    /**
     * Retrieve the public list of predefined system counters.
     *
     * No authentication is required. Counters are returned in their strictly defined
     * display order, omitting internal system metadata.
     */
    public function index(): JsonResponse
    {
        $counters = Counter::ordered()->get();

        return response()->json([
            'data' => CounterResource::collection($counters),
        ], 200);
    }
}
