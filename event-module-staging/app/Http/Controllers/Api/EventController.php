<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\IndexPublicEventRequest;
use App\Http\Resources\EventPublicResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;

class EventController extends Controller
{
    /**
     * Default page size for public event listing.
     */
    public const PER_PAGE = 20;

    /**
     * List published events with category and timeline filters.
     *
     * Public endpoint, no authentication required.
     * Drafts are strictly excluded.
     */
    public function index(IndexPublicEventRequest $request): JsonResponse
    {
        $query = Event::query()->with('category')->published();

        // Category filter
        if ($request->filled('category')) {
            $query->forCategory((string) $request->input('category'));
        }

        // Timeline filter (default: upcoming)
        $timeline = $request->input('timeline', 'upcoming');
        if ($timeline === 'upcoming') {
            $query->upcoming();
        } elseif ($timeline === 'past') {
            $query->past();
        } elseif ($timeline === 'latest') {
            $query->orderBy('created_at', 'desc')->orderBy('id', 'desc');
        } else {
            $query->orderedForPublic();
        }

        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = $query->paginate($perPage);

        return EventPublicResource::collection($paginated)->response();
    }

    /**
     * Display a single published event by slug.
     *
     * Public endpoint, no authentication required.
     * Draft events cannot be retrieved and return HTTP 404.
     */
    public function show(string $slug): JsonResponse
    {
        $event = Event::query()
            ->with('category')
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        return response()->json([
            'data' => new EventPublicResource($event),
        ], 200);
    }
}
