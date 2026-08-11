<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\Products\ProductService;
use App\Support\ApiResponse;
use App\Support\QueryOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Products (Phase 5).
 *
 * Thin: HTTP translation only, rules live in ProductService (ARCHITECTURE §2).
 */
class ProductController extends Controller
{
    public function __construct(private readonly ProductService $products) {}

    public function index(Request $request): JsonResponse
    {
        $options = new QueryOptions(
            $request,
            allowedFilters: ['is_active', 'delivery_type', 'code'],
            allowedSorts: ['sort_order', 'name', 'code', 'base_price', 'created_at'],
            allowedIncludes: [],
        );

        $query = Product::query()->withCount('leadProducts');

        // Archived products are hidden unless explicitly requested, so they
        // vanish from dropdowns and campaign targeting without being destroyed.
        if ($request->boolean('with_archived')) {
            $query->withTrashed();
        }

        // Default ordering matches how the business lists its products.
        if (! $request->filled('sort')) {
            $query->orderBy('sort_order')->orderBy('name');
        }

        $products = $options->applyTo($query)->paginate($options->perPage());

        return ApiResponse::paginated(
            ProductResource::collection($products),
            'Products retrieved.',
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->loadCount('leadProducts');

        return ApiResponse::success(new ProductResource($product));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated(), $request->user()->id);

        return ApiResponse::created(
            new ProductResource($product),
            'Product created.',
        );
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->products->update($product, $request->validated(), $request->user()->id);

        return ApiResponse::success(new ProductResource($product), 'Product updated.');
    }

    /**
     * Archives rather than deletes - product history feeds performance
     * reporting and must survive.
     */
    public function destroy(Product $product): JsonResponse
    {
        $this->products->archive($product);

        return ApiResponse::success(message: 'Product archived.');
    }

    public function restore(int $id): JsonResponse
    {
        $product = Product::withTrashed()->findOrFail($id);

        return ApiResponse::success(
            new ProductResource($this->products->restore($product)),
            'Product restored.',
        );
    }
}
