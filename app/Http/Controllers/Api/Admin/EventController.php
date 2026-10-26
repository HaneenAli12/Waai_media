<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Events\IndexEventRequest;
use App\Http\Requests\Admin\Events\PublishEventRequest;
use App\Http\Requests\Admin\Events\StoreEventRequest;
use App\Http\Requests\Admin\Events\UpdateEventRequest;
use App\Http\Resources\EventAdminResource;
use App\Models\Event;
use App\Services\Events\EventCoverImageService;
use App\Services\Events\EventSlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventController extends Controller
{
    /**
     * Default page size for admin event listing (10 events per page for Admin Dashboard).
     */
    public const PER_PAGE = 10;

    public function __construct(
        protected EventSlugService $slugService,
        protected EventCoverImageService $coverImageService
    ) {}

    /**
     * List events with pagination (default 10), category & status filters, timeline, and English title search.
     *
     * Accessible by authorized Admin and Editor roles.
     * Page size defaults to 10 events per page matching the Admin Management specification.
     */
    public function index(IndexEventRequest $request): JsonResponse
    {
        $query = Event::query()->orderedForAdmin();

        // Search by event name (case-insensitive search by English name and Arabic name)
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name_en', 'LIKE', "%{$escaped}%")
                    ->orWhere('name_ar', 'LIKE', "%{$escaped}%");
            });
        }

        // Category filter (bilingual text matching)
        if ($request->filled('category')) {
            $query->forCategory((string) $request->input('category'));
        }

        // Publication status filter (strictly draft, published)
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Timeline filter (upcoming or past)
        if ($request->filled('timeline')) {
            $timeline = $request->input('timeline');
            if ($timeline === 'upcoming') {
                $query->upcoming();
            } elseif ($timeline === 'past') {
                $query->past();
            }
        }

        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = $query->paginate($perPage);

        return EventAdminResource::collection($paginated)->response();
    }

    /**
     * Display a single event.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function show(Event $event): JsonResponse
    {
        return response()->json([
            'data' => new EventAdminResource($event),
        ], 200);
    }

    /**
     * Create a new draft event.
     *
     * Accessible by authorized Admin and Editor roles.
     * Slug is derived using the persisted database ID once saved.
     */
    public function store(StoreEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $uploadedKey = null;
        if ($request->hasFile('cover_image')) {
            $uploadedKey = $this->coverImageService->upload($request->file('cover_image'));
            $validated['cover_image_url'] = $uploadedKey;
        }
        unset($validated['cover_image']);

        $validated['status'] = Event::STATUS_DRAFT;
        $validated['published_at'] = null;
        $validated['slug'] = null;

        try {
            $event = DB::transaction(function () use ($validated) {
                $created = Event::create($validated);

                $slug = $this->slugService->generateSlug($created->name_en, $created->id);
                if ($slug === null) {
                    throw new \RuntimeException('Failed to generate a valid slug from the provided English event title.');
                }

                $created->update(['slug' => $slug]);

                return $created;
            });
        } catch (\Throwable $e) {
            if ($uploadedKey !== null) {
                $this->coverImageService->deleteIfUnshared($uploadedKey);
            }

            throw $e;
        }

        return response()->json([
            'data' => new EventAdminResource($event),
        ], 201);
    }

    /**
     * Update an existing event.
     *
     * Accessible by authorized Admin and Editor roles.
     * Partial updates preserve omitted fields. Slug regenerates only before first publication.
     */
    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();

        // Disallow client-controlled mutation of protected lifecycle attributes
        unset($validated['id'], $validated['status'], $validated['published_at'], $validated['slug']);

        $uploadedKey = null;
        if ($request->hasFile('cover_image')) {
            $uploadedKey = $this->coverImageService->upload($request->file('cover_image'));
            $validated['cover_image_url'] = $uploadedKey;
        }
        unset($validated['cover_image']);

        $hasNameEn = $request->has('name_en');
        if ($hasNameEn) {
            $newNameEn = $request->input('name_en');
            $validated['slug'] = $this->slugService->resolveSlugForUpdate($event, $newNameEn, true);
        }

        $previousCoverImage = $event->cover_image_url;

        try {
            DB::transaction(function () use ($event, $validated) {
                $event->update($validated);
            });
        } catch (\Throwable $e) {
            if ($uploadedKey !== null) {
                $this->coverImageService->deleteIfUnshared($uploadedKey);
            }

            throw $e;
        }

        // Clean up previous image if a new image was uploaded or image replaced
        if (array_key_exists('cover_image_url', $validated) && $previousCoverImage !== null && $previousCoverImage !== $validated['cover_image_url']) {
            $this->coverImageService->deleteIfUnshared($previousCoverImage, $event->id);
        }

        $fresh = $event->fresh();

        return response()->json([
            'data' => new EventAdminResource($fresh),
        ], 200);
    }

    /**
     * Publish an event.
     *
     * Accessible by authorized Admin and Editor roles.
     * Validates full bilingual completeness and mandatory fields.
     * Sets published_at on initial publish and preserves historical timestamp on republish.
     */
    public function publish(PublishEventRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();

        unset($validated['id'], $validated['status'], $validated['published_at']);

        DB::transaction(function () use ($event, $validated) {
            $slug = $event->slug;
            if ($slug === null) {
                $nameEn = $validated['name_en'] ?? $event->name_en;
                $slug = $this->slugService->generateSlug($nameEn, $event->id);
                if ($slug === null) {
                    throw new \RuntimeException('Failed to generate a valid slug before publishing.');
                }
                $validated['slug'] = $slug;
            }

            $validated['status'] = Event::STATUS_PUBLISHED;

            if ($event->published_at === null) {
                $validated['published_at'] = now();
            }

            $event->update($validated);
        });

        $fresh = $event->fresh();

        return response()->json([
            'data' => new EventAdminResource($fresh),
        ], 200);
    }

    /**
     * Convert a published event back into a draft (unpublish).
     *
     * Accessible by authorized Admin and Editor roles.
     * Transitions status from published to draft and clears published_at.
     * The slug remains permanently assigned and immutable.
     */
    public function unpublish(Event $event): JsonResponse
    {
        DB::transaction(function () use ($event) {
            $event->update([
                'status' => Event::STATUS_DRAFT,
                'published_at' => null,
            ]);
        });

        return response()->json([
            'message' => 'Event converted to draft successfully.',
            'data' => new EventAdminResource($event->fresh()),
        ], 200);
    }

    /**
     * Permanently delete an event.
     *
     * Accessible by Administrator ONLY. Editors are strictly forbidden (HTTP 403).
     * Dual-layer safeguard: route middleware 'role:admin' + controller-level authorization check.
     */
    public function destroy(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAnyAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete events.',
            ], 403);
        }

        $coverImageUrl = $event->cover_image_url;
        $eventId = $event->id;

        DB::transaction(function () use ($event) {
            $event->delete();
        });

        // Delete unshared R2 object only after transaction successfully commits
        if ($coverImageUrl !== null) {
            $this->coverImageService->deleteIfUnshared($coverImageUrl, $eventId);
        }

        return response()->json([
            'message' => 'Event permanently deleted successfully.',
        ], 200);
    }
}
