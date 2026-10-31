<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portfolio\IndexPublicPortfolioRequest;
use App\Http\Requests\Portfolio\PublicPortfolioClientNavigationRequest;
use App\Http\Requests\Portfolio\PublicPortfolioClientsRequest;
use App\Http\Resources\PortfolioClientCardResource;
use App\Http\Resources\PortfolioItemPublicResource;
use App\Models\Category;
use App\Models\Client;
use App\Models\PortfolioItem;
use App\Services\Portfolio\PortfolioImageService;
use App\Support\LocaleNegotiator;
use App\Support\Portfolio\PortfolioCategoryPresenter;
use App\Support\Portfolio\PortfolioClientPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PortfolioController extends Controller
{
    /**
     * Default page size for the public portfolio listing.
     */
    public const PER_PAGE = 12;

    /**
     * Experience A - Portfolio cards: published items only, newest published first, localized by Accept-Language.
     *
     * Filters: category_id (main category, includes its subcategories), subcategory_id, client_id; they combine.
     * The homepage uses ?per_page=3.
     */
    public function index(IndexPublicPortfolioRequest $request): JsonResponse
    {
        $query = PortfolioItem::query()
            ->published()
            ->with(['client', 'category', 'subcategory'])
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc');

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

        return PortfolioItemPublicResource::collection($query->paginate($perPage))->response();
    }

    /**
     * A single published item with its content and the client's other published items in the same main category
     * (for the subcategory tabs); drafts and unknown ids return 404.
     */
    public function show(int $id): JsonResponse
    {
        $item = PortfolioItem::query()
            ->published()
            ->with(['client', 'category', 'subcategory', 'media'])
            ->findOrFail($id);

        $siblings = $item->client_id === null
            ? collect([$item])
            : PortfolioItem::query()
                ->published()
                ->with('subcategory')
                ->where('client_id', $item->client_id)
                ->where('category_id', $item->category_id)
                ->orderBy('subcategory_id')
                ->orderBy('id')
                ->get();

        $item->setRelation('siblings', $this->orderByCategoryPosition($siblings));

        return response()->json(['data' => new PortfolioItemPublicResource($item)], 200);
    }

    /**
     * Experience B - Client cards: each client with at least one published item in the selected main category,
     * exactly once (EXISTS, no join duplicates), newest client first with id as the tiebreaker.
     */
    public function clients(PublicPortfolioClientsRequest $request): JsonResponse
    {
        $categoryId = (int) $request->input('category_id');
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));
        $category = Category::findOrFail($categoryId);

        $published = fn ($q) => $q
            ->where('portfolio_items.category_id', $categoryId)
            ->where('portfolio_items.status', PortfolioItem::STATUS_PUBLISHED)
            ->whereNotNull('portfolio_items.published_at')
            ->where('portfolio_items.published_at', '<=', now());

        $paginated = Client::query()
            ->whereExists(fn ($sub) => $published($sub->selectRaw('1')->from('portfolio_items')->whereColumn('portfolio_items.client_id', 'clients.id')))
            ->addSelect(['published_items_count' => $published(DB::table('portfolio_items')->selectRaw('count(*)')->whereColumn('portfolio_items.client_id', 'clients.id'))])
            ->orderedForAdmin()
            ->paginate((int) $request->input('per_page', self::PER_PAGE));

        $categoryData = PortfolioCategoryPresenter::present($category, $locale);

        $paginated->getCollection()->each(fn (Client $client) => $client->setAttribute('portfolio_category', $categoryData));

        return PortfolioClientCardResource::collection($paginated)->response();
    }

    /**
     * Subcategory tabs of one client inside one main category: only subcategories holding a published item for
     * that client. Web Design (no subcategories) returns a single tab without a subcategory.
     */
    public function clientNavigation(PublicPortfolioClientNavigationRequest $request, int $clientId): JsonResponse
    {
        $locale = LocaleNegotiator::negotiate($request->header('Accept-Language'));
        $images = app(PortfolioImageService::class);

        $client = Client::findOrFail($clientId);
        $category = Category::findOrFail((int) $request->input('category_id'));

        $items = PortfolioItem::query()
            ->published()
            ->with('subcategory')
            ->where('client_id', $client->id)
            ->where('category_id', $category->id)
            ->get();

        abort_if($items->isEmpty(), 404, 'This client has no published portfolio in the selected category.');

        $tabs = $this->orderByCategoryPosition($items)->map(fn (PortfolioItem $item) => [
            'portfolio_id' => (int) $item->id,
            'title' => $locale === 'ar' ? ($item->title_ar ?: $item->title_en) : ($item->title_en ?: $item->title_ar),
            'cover_image_url' => $images->resolveUrl($item->cover_image_path),
            'subcategory' => PortfolioCategoryPresenter::present($item->subcategory, $locale),
            'published_at' => $item->published_at?->toISOString(),
        ])->values();

        return response()->json(['data' => [
            'client' => PortfolioClientPresenter::present($client),
            'category' => PortfolioCategoryPresenter::present($category, $locale),
            'default_portfolio_id' => $tabs->first()['portfolio_id'],
            'items' => $tabs->all(),
        ]], 200);
    }

    /**
     * Order items by the configured position of their subcategory (the category sort_order), then id.
     *
     * @param  Collection<int, PortfolioItem>  $items
     * @return Collection<int, PortfolioItem>
     */
    private function orderByCategoryPosition($items)
    {
        return $items
            ->sortBy([
                fn ($a, $b) => ($a->subcategory?->sort_order ?? 0) <=> ($b->subcategory?->sort_order ?? 0),
                fn ($a, $b) => $a->id <=> $b->id,
            ])
            ->values();
    }
}
