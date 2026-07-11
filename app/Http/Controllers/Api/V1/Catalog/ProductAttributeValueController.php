<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreProductAttributeValueRequest;
use App\Http\Requests\Catalog\UpdateProductAttributeValueRequest;
use App\Http\Resources\Catalog\ProductAttributeValueResource;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Services\ProductAttributeValueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * قيم السمة (متداخلة تحت السمة). محكومة بصلاحيات السمات (مورد فرعي).
 */
class ProductAttributeValueController extends Controller
{
    public function __construct(private readonly ProductAttributeValueService $service)
    {
        //
    }

    public function index(Request $request, ProductAttribute $attribute): AnonymousResourceCollection
    {
        $this->authorize('view', $attribute);

        $query = $attribute->values()->getQuery();

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $this->applySorting($query, $request, ['value', 'sort_order'], 'sort_order', 'asc');

        return ProductAttributeValueResource::collection($query->paginate($this->perPage(50)));
    }

    public function store(StoreProductAttributeValueRequest $request, ProductAttribute $attribute): ProductAttributeValueResource
    {
        $this->authorize('update', $attribute);

        return new ProductAttributeValueResource($this->service->create($attribute, $request->validated()));
    }

    public function show(ProductAttribute $attribute, ProductAttributeValue $value): ProductAttributeValueResource
    {
        $this->authorize('view', $attribute);

        return new ProductAttributeValueResource($value);
    }

    public function update(UpdateProductAttributeValueRequest $request, ProductAttribute $attribute, ProductAttributeValue $value): ProductAttributeValueResource
    {
        $this->authorize('update', $attribute);

        return new ProductAttributeValueResource($this->service->update($value, $request->validated()));
    }

    public function destroy(ProductAttribute $attribute, ProductAttributeValue $value): JsonResponse
    {
        $this->authorize('update', $attribute);

        $this->service->delete($value);

        return response()->json(['message' => __('تم حذف القيمة.')]);
    }
}
