<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\AddCartItemRequest;
use App\Http\Requests\Store\UpdateCartItemRequest;
use App\Http\Resources\Store\CartResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Services\CartService;
use Illuminate\Http\Request;

/**
 * سلة الهوية الحالية (self-scoped): مستخدم مُصادَق أو ضيف برمز سلة (Phase 3.2).
 */
class CartController extends Controller
{
    public function __construct(private readonly CartService $service) {}

    public function show(Request $request): CartResource
    {
        return $this->respond($request);
    }

    public function addItem(AddCartItemRequest $request): CartResource
    {
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $this->service->addItem($this->cart($request), $variant, (float) $request->validated('qty'));

        return $this->respond($request);
    }

    public function updateItem(UpdateCartItemRequest $request, string $variantUuid): CartResource
    {
        $variant = ProductVariant::where('uuid', $variantUuid)->firstOrFail();
        $this->service->setItem($this->cart($request), $variant, (float) $request->validated('qty'));

        return $this->respond($request);
    }

    public function removeItem(Request $request, string $variantUuid): CartResource
    {
        $variant = ProductVariant::where('uuid', $variantUuid)->firstOrFail();
        $this->service->removeItem($this->cart($request), $variant);

        return $this->respond($request);
    }

    public function clear(Request $request): CartResource
    {
        $this->service->clear($this->cart($request));

        return $this->respond($request);
    }

    /** سلة الهوية الحالية (تضمنها طبقة store.identity — مستخدم أو ضيف). */
    private function cart(Request $request): Cart
    {
        return $this->service->resolveActive($request->user(), $request->header('X-Cart-Token'))
            ?? abort(401);
    }

    private function respond(Request $request): CartResource
    {
        // نسخة حديثة: تفادي 201 التلقائي على GET حين تُنشأ السلة أوّل مرّة (wasRecentlyCreated).
        // تحميل مسبق للاسم والصورة والخيارات: بدونه استعلام لكل بند في السلة.
        $cart = $this->cart($request)->fresh([
            'items.variant.product.primaryImage',
            'items.variant.attributeValues',
        ]);

        return new CartResource($cart);
    }
}
