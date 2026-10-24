<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\News\IndexNewsRequest;
use App\Http\Requests\Admin\News\PublishNewsRequest;
use App\Http\Requests\Admin\News\StoreNewsRequest;
use App\Http\Requests\Admin\News\UpdateNewsRequest;
use App\Http\Resources\NewsArticleResource;
use App\Models\Category;
use App\Models\NewsArticle;
use App\Services\News\NewsSlugService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewsController extends Controller
{
    /**
     * Fixed page size for news listing.
     */
    public const PER_PAGE = 20;

    public function __construct(
        protected NewsSlugService $slugService
    ) {}

    /**
     * List news articles with fixed pagination, category & status filters, and title search.
     *
     * Accessible by authorized Admin and Editor roles.
     * Page size is permanently fixed at 20 and cannot be overridden by query parameters.
     */
    public function index(IndexNewsRequest $request): JsonResponse
    {
        $query = NewsArticle::query()
            ->with(['category', 'author'])
            ->orderedForAdmin();

        // Title search (Arabic and English only)
        if ($request->filled('search')) {
            $search = trim((string) $request->input('search'));
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escaped) {
                $q->where('title_en', 'LIKE', "%{$escaped}%")
                    ->orWhere('title_ar', 'LIKE', "%{$escaped}%");
            });
        }

        // Category filter (including organizational parent expansion, e.g. Entertainment -> children)
        if ($request->filled('category_id')) {
            $categoryId = (int) $request->input('category_id');
            $category = Category::find($categoryId);

            if ($category && $category->children()->exists()) {
                $childIds = $category->children()->pluck('id')->toArray();
                $query->whereIn('category_id', $childIds);
            } else {
                $query->where('category_id', $categoryId);
            }
        }

        // Publication status filter (draft, published, unpublished)
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $paginated = $query->paginate(self::PER_PAGE);

        return NewsArticleResource::collection($paginated)->response();
    }

    /**
     * Display a single news article with category and author.
     *
     * Accessible by authorized Admin and Editor roles.
     */
    public function show(NewsArticle $news): JsonResponse
    {
        $news->load(['category', 'author']);

        return response()->json([
            'data' => new NewsArticleResource($news),
        ], 200);
    }

    /**
     * Create a new draft article.
     *
     * Accessible by authorized Admin and Editor roles.
     * Validated content is pre-sanitized. Slug is derived using the persisted database ID.
     */
    public function store(StoreNewsRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['author_id'] = $request->user()->id;
        $validated['status'] = NewsArticle::STATUS_DRAFT;
        $validated['published_at'] = null;
        $validated['slug'] = null;

        $article = DB::transaction(function () use ($validated) {
            $created = NewsArticle::create($validated);

            $slug = $this->slugService->generateSlug($created->title_en, $created->id);
            if ($slug !== null) {
                $created->update(['slug' => $slug]);
            }

            return $created;
        });

        return response()->json([
            'data' => new NewsArticleResource($article->load(['category', 'author'])),
        ], 201);
    }

    /**
     * Update an existing news article.
     *
     * Accessible by authorized Admin and Editor roles.
     * Partial updates preserve omitted fields. Slug regenerates only for unpublished drafts.
     */
    public function update(UpdateNewsRequest $request, NewsArticle $news): JsonResponse
    {
        $validated = $request->validated();

        // Disallow client-controlled mutation of protected lifecycle attributes
        unset($validated['id'], $validated['author_id'], $validated['status'], $validated['published_at'], $validated['slug']);

        $hasTitleEn = $request->has('title_en');
        if ($hasTitleEn) {
            $newTitleEn = $request->input('title_en');
            $validated['slug'] = $this->slugService->resolveSlugForUpdate($news, $newTitleEn, true);
        }

        DB::transaction(function () use ($news, $validated) {
            $news->update($validated);
        });

        return response()->json([
            'data' => new NewsArticleResource($news->fresh()->load(['category', 'author'])),
        ], 200);
    }

    /**
     * Publish an article.
     *
     * Accessible by authorized Admin and Editor roles.
     * Validates full effective bilingual completeness, leaf category, and cover key.
     * Sets published_at on initial publish and preserves historical timestamp on republish.
     */
    public function publish(PublishNewsRequest $request, NewsArticle $news): JsonResponse
    {
        $validated = $request->validated();

        unset($validated['id'], $validated['author_id'], $validated['status'], $validated['published_at']);

        DB::transaction(function () use ($news, $validated) {
            if ($news->slug === null) {
                $titleEn = $validated['title_en'] ?? $news->title_en;
                $validated['slug'] = $this->slugService->generateSlug($titleEn, $news->id);
            }

            $validated['status'] = NewsArticle::STATUS_PUBLISHED;

            if ($news->published_at === null) {
                $validated['published_at'] = now();
            }

            $news->update($validated);
        });

        return response()->json([
            'data' => new NewsArticleResource($news->fresh()->load(['category', 'author'])),
        ], 200);
    }

    /**
     * Unpublish an article.
     *
     * Accessible by authorized Admin and Editor roles.
     * Sets status to unpublished and published_at to null. Slug remains permanently immutable.
     */
    public function unpublish(NewsArticle $news): JsonResponse
    {
        DB::transaction(function () use ($news) {
            $news->update([
                'status' => NewsArticle::STATUS_UNPUBLISHED,
                'published_at' => null,
            ]);
        });

        return response()->json([
            'data' => new NewsArticleResource($news->fresh()->load(['category', 'author'])),
        ], 200);
    }

    /**
     * Permanently delete a news article.
     *
     * Accessible by Administrator ONLY. Editors are strictly forbidden (HTTP 403).
     */
    public function destroy(Request $request, NewsArticle $news): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete news articles.',
            ], 403);
        }

        DB::transaction(function () use ($news) {
            $news->delete();
        });

        return response()->json([
            'message' => 'News article permanently deleted successfully.',
        ], 200);
    }
}
