<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Blog\IndexPublicBlogRequest;
use App\Http\Resources\BlogPublicResource;
use App\Models\Blog;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class BlogController extends Controller
{
    /**
     * Default page size for public blog listing (6 items per page for latest/category tabs).
     */
    public const PER_PAGE = 6;

    /**
     * Number of latest published articles returned for the homepage.
     */
    public const LATEST_LIMIT = 3;

    /**
     * List published blog articles only, with optional category filtering and pagination.
     *
     * Public endpoint, no authentication required.
     * Drafts are strictly excluded.
     */
    public function index(IndexPublicBlogRequest $request): JsonResponse
    {
        $query = Blog::query()
            ->published()
            ->with(['category.parent', 'featuredImage'])
            ->orderedForPublic();

        $this->applyCategoryFilter($query, $request->input('category_id'), $request->input('category'));

        if ($request->filled('limit') && ! $request->filled('per_page') && ! $request->filled('page')) {
            $limit = (int) $request->input('limit');
            $items = $query->limit($limit)->get();

            return response()->json([
                'data' => BlogPublicResource::collection($items),
            ], 200);
        }

        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        $paginated = $query->paginate($perPage);

        return BlogPublicResource::collection($paginated)->response();
    }

    /**
     * Return the latest three published blog articles for the homepage.
     *
     * Public endpoint, no authentication required.
     * Includes both Arabic and English category names.
     */
    public function latest(IndexPublicBlogRequest $request): JsonResponse
    {
        $limit = (int) $request->input('limit', self::LATEST_LIMIT);

        $query = Blog::query()
            ->published()
            ->with(['category.parent', 'featuredImage'])
            ->orderedForPublic();

        $this->applyCategoryFilter($query, $request->input('category_id'), $request->input('category'));

        $articles = $query->limit($limit)->get();

        return response()->json([
            'data' => BlogPublicResource::collection($articles),
        ], 200);
    }

    /**
     * Retrieve a single published blog article by slug or numeric ID.
     *
     * Draft articles are strictly isolated and return HTTP 404.
     */
    public function show(string $identifier): JsonResponse
    {
        $query = Blog::query()
            ->published()
            ->with(['category.parent', 'featuredImage']);

        if (ctype_digit($identifier)) {
            $query->where(function ($q) use ($identifier) {
                $q->where('id', (int) $identifier)
                    ->orWhere('slug', $identifier);
            });
        } else {
            $query->where('slug', $identifier);
        }

        $blog = $query->firstOrFail();

        return response()->json([
            'data' => new BlogPublicResource($blog),
        ], 200);
    }

    /**
     * Apply category filter by ID, slug, or name (supporting parent category expansion).
     */
    private function applyCategoryFilter($query, mixed $categoryId, mixed $categoryParam): void
    {
        if ($categoryId !== null && $categoryId !== '') {
            $cat = Category::where('type', 'blogs')->find((int) $categoryId);
            if ($cat) {
                $ids = $this->resolveCategoryFilterIds($cat);
                $query->whereIn('category_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }

            return;
        }

        if ($categoryParam !== null && trim((string) $categoryParam) !== '') {
            $trimmed = trim((string) $categoryParam);

            $cat = Category::where('type', 'blogs')
                ->where(function ($q) use ($trimmed) {
                    if (ctype_digit($trimmed)) {
                        $q->where('id', (int) $trimmed)->orWhere('slug', $trimmed);
                    } else {
                        $q->where('slug', $trimmed)
                            ->orWhere('name_en', $trimmed)
                            ->orWhere('name_ar', $trimmed);
                    }
                })
                ->first();

            if ($cat) {
                $ids = $this->resolveCategoryFilterIds($cat);
                $query->whereIn('category_id', $ids);
            } else {
                $query->whereRaw('1 = 0');
            }
        }
    }

    /**
     * Resolve category IDs for filtering (if a parent category is selected,
     * include all its child subcategory IDs).
     *
     * @return list<int>
     */
    private function resolveCategoryFilterIds(Category $category): array
    {
        $childIds = $category->children()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_merge([(int) $category->id], $childIds)));
    }
}
