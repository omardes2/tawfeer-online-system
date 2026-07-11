<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductImageRequest;
use App\Http\Resources\Catalog\ProductImageResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\ProductImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * وسائط المنتج (متداخلة). محكومة بصلاحيات المنتج الأب (view/update).
 */
class ProductImageController extends Controller
{
    public function __construct(private readonly ProductImageService $service) {}

    public function index(Product $product): AnonymousResourceCollection
    {
        $this->authorize('view', $product);

        return ProductImageResource::collection($product->images);
    }

    public function store(StoreProductImageRequest $request, Product $product): ProductImageResource
    {
        $this->authorize('update', $product);

        $image = $this->service->store($product, $request->file('image'), $request->safe()->except('image'));

        return new ProductImageResource($image);
    }

    public function setPrimary(Product $product, ProductImage $image): ProductImageResource
    {
        $this->authorize('update', $product);

        return new ProductImageResource($this->service->setPrimary($product, $image));
    }

    public function destroy(Product $product, ProductImage $image): JsonResponse
    {
        $this->authorize('update', $product);

        $this->service->delete($image);

        return response()->json(['message' => __('تم حذف الصورة.')]);
    }
}
