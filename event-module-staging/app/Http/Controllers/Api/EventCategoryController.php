<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventCategoryPublicResource;
use App\Models\EventCategory;
use Illuminate\Http\JsonResponse;

class EventCategoryController extends Controller
{
    /**
     * List all active event categories ordered by sort_order asc, id asc.
     *
     * Public endpoint, no authentication required.
     */
    public function index(): JsonResponse
    {
        $categories = EventCategory::query()
            ->active()
            ->ordered()
            ->get();

        return response()->json([
            'data' => EventCategoryPublicResource::collection($categories),
        ], 200);
    }
}
