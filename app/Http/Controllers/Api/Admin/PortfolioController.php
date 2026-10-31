<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Portfolio\IndexPortfolioRequest;
use App\Http\Requests\Admin\Portfolio\StorePortfolioRequest;
use App\Http\Requests\Admin\Portfolio\UpdatePortfolioRequest;
use App\Http\Resources\PortfolioItemAdminResource;
use App\Models\Category;
use App\Models\PortfolioItem;
use App\Services\Portfolio\PortfolioItemService;
use App\Support\LocaleNegotiator;
use App\Support\Portfolio\PortfolioKind;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    /**
     * Default page size for the admin portfolio listing.
     */
    public const PER_PAGE = 10;

    public function __construct(
        protected PortfolioItemService $portfolio
    ) {}

    /**
     * Category/subcategory hierarchy with the input contract of each content kind, for the dashboard form.
     */
    public function categories(Request $request): JsonResponse
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));

        $parents = Category::where('type', 'portfolio')
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        $data = $parents->map(function (Category $parent) use ($locale) {
            $children = $parent->children;
            $kind = $children->isEmpty() ? PortfolioKind::resolve($parent, null) : null;

            return [
                'id' => (int) $parent->id,
                'slug' => (string) $parent->slug,
                'name' => $locale === 'ar' ? $parent->name_ar : $parent->name_en,
                'name_en' => $parent->name_en,
                'name_ar' => $parent->name_ar,
                'has_subcategories' => $children->isNotEmpty(),
                'kind' => $kind,
                'inputs' => $kind ? PortfolioKind::describe($kind) : null,
                'subcategories' => $children->map(function (Category $child) use ($parent, $locale) {
                    $kind = PortfolioKind::resolve($parent, $child);

                    return [
                        'id' => (int) $child->id,
                        'slug' => (string) $child->slug,
                        'name' => $locale === 'ar' ? $child->name_ar : $child->name_en,
                        'name_en' => $child->name_en,
                        'name_ar' => $child->name_ar,
                        'kind' => $kind,
                        'inputs' => $kind ? PortfolioKind::describe($kind) : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        return response()->json(['data' => $data], 200);
    }

    /**
     * List portfolio items with server-side search, filters and page-number pagination.
     */
    public function index(IndexPortfolioRequest $request): JsonResponse
    {
        $query = PortfolioItem::query()
            ->with(['client', 'category', 'subcategory'])
            ->withCount('media')
            ->orderedForAdmin();

        if ($request->filled('search')) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim((string) $request->input('search')));
            $query->where(function ($q) use ($escaped) {
                $q->whereRaw("title_en LIKE ? ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("title_ar LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', (int) $request->input('client_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', (int) $request->input('category_id'));
        }

        if ($request->filled('subcategory_id')) {
            $query->where('subcategory_id', (int) $request->input('subcategory_id'));
        }

        $perPage = (int) $request->input('per_page', self::PER_PAGE);

        return PortfolioItemAdminResource::collection($query->paginate($perPage))->response();
    }

    /**
     * Display a single portfolio item with its media.
     */
    public function show(PortfolioItem $portfolioItem): JsonResponse
    {
        $portfolioItem->load(['client', 'category', 'subcategory', 'media']);

        return response()->json(['data' => new PortfolioItemAdminResource($portfolioItem)], 200);
    }

    /**
     * Create an item. Saved as a draft unless `status=published` is sent (then publish rules apply).
     */
    public function store(StorePortfolioRequest $request): JsonResponse
    {
        $item = $this->portfolio->save(null, $request->serviceInput());

        return response()->json(['data' => new PortfolioItemAdminResource($item)], 201);
    }

    /**
     * Update an item. The status is kept unless `status` is sent; a published item must stay publish-ready.
     */
    public function update(UpdatePortfolioRequest $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $item = $this->portfolio->save($portfolioItem, $request->serviceInput());

        return response()->json(['data' => new PortfolioItemAdminResource($item)], 200);
    }

    /**
     * Publish the stored item (fails with 422 when it is not publish-ready).
     */
    public function publish(PortfolioItem $portfolioItem): JsonResponse
    {
        $item = $this->portfolio->setStatus($portfolioItem, PortfolioItem::STATUS_PUBLISHED);

        return response()->json(['data' => new PortfolioItemAdminResource($item)], 200);
    }

    /**
     * Convert a published item back into a draft.
     */
    public function unpublish(PortfolioItem $portfolioItem): JsonResponse
    {
        $item = $this->portfolio->setStatus($portfolioItem, PortfolioItem::STATUS_DRAFT);

        return response()->json(['data' => new PortfolioItemAdminResource($item)], 200);
    }

    /**
     * Permanently delete an item and its media.
     *
     * Admin and Super Admin only (route middleware + controller-level check); editors receive 403.
     */
    public function destroy(Request $request, PortfolioItem $portfolioItem): JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $user->isAnyAdmin()) {
            return response()->json([
                'message' => 'Forbidden. Only administrators can permanently delete portfolio items.',
            ], 403);
        }

        $this->portfolio->delete($portfolioItem);

        return response()->json(['message' => 'Portfolio item deleted successfully.'], 200);
    }
}
