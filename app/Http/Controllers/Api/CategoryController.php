<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryIndexRequest;
use App\Http\Resources\CategoryChildResource;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Retrieve categories for a given module type.
     *
     * Public endpoint, no authentication required.
     * Accessible at: GET /api/v1/categories?type={news|blogs|portfolio}
     *
     * News and Portfolio return top-level categories with eager-loaded children.
     * Blogs returns a flat category array.
     */
    public function index(CategoryIndexRequest $request): JsonResponse
    {
        $type = $request->validated('type');

        // Mode C: the children of one selected parent (flat list)
        if ($request->filled('parent_id')) {
            $children = Category::where('type', $type)
                ->where('parent_id', (int) $request->input('parent_id'))
                ->orderBy('sort_order', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            return response()->json(['data' => CategoryChildResource::collection($children)], 200);
        }

        // Mode A: parents only, without the children key
        $parentsOnly = $request->input('level') === 'parent'
            || ($request->has('include_children') && ! $request->boolean('include_children'));

        $query = Category::where('type', $type)
            ->whereNull('parent_id')
            ->orderBy('sort_order', 'asc');

        if (! $parentsOnly && in_array($type, ['news', 'portfolio'], true)) {
            $query->with(['children' => fn ($q) => $q->orderBy('sort_order', 'asc')]);
        }

        $categories = $query->get();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ], 200);
    }
}
