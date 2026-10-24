<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EventCategories\StoreEventCategoryRequest;
use App\Http\Requests\Admin\EventCategories\UpdateEventCategoryRequest;
use App\Http\Resources\EventCategoryResource;
use App\Models\EventCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class EventCategoryController extends Controller
{
    /**
     * List all event categories (including inactive ones) ordered for admin management.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function index(): JsonResponse
    {
        $categories = EventCategory::query()
            ->orderBy('sort_order', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        return response()->json([
            'data' => EventCategoryResource::collection($categories),
        ], 200);
    }

    /**
     * Create a new event category.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function store(StoreEventCategoryRequest $request): JsonResponse
    {
        $category = EventCategory::create($request->validated());

        return response()->json([
            'data' => new EventCategoryResource($category),
        ], 201);
    }

    /**
     * Display a specific event category.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function show(EventCategory $eventCategory): JsonResponse
    {
        return response()->json([
            'data' => new EventCategoryResource($eventCategory),
        ], 200);
    }

    /**
     * Update an event category (full or partial).
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function update(UpdateEventCategoryRequest $request, EventCategory $eventCategory): JsonResponse
    {
        $eventCategory->update($request->validated());

        return response()->json([
            'data' => new EventCategoryResource($eventCategory->fresh()),
        ], 200);
    }

    /**
     * Delete an event category.
     *
     * Strictly restricted to the Administrator role via route middleware.
     * Underlying foreign key constraint sets related events' event_category_id to NULL.
     */
    public function destroy(EventCategory $eventCategory): JsonResponse
    {
        DB::transaction(function () use ($eventCategory) {
            $eventCategory->delete();
        });

        return response()->json([
            'message' => 'Event category deleted successfully.',
        ], 200);
    }
}
