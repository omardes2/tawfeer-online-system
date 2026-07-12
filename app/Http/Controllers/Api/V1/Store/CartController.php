<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\AddCartItemRequest;
use App\Http\Requests\Store\UpdateCartItemRequest;
use App\Http\Resources\Store\CartResource;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Store\Services\CartService;
use Illuminate\Http\Request;

/**
 * سلة المستخدم الحالي (self-scoped بالمصادقة — لا تُدار سلات الآخرين).
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
        $cart = $this->service->forUser($request->user());
        $variant = ProductVariant::where('uuid', $request->validated('variant'))->firstOrFail();
        $this->service->addItem($cart, $variant, (float) $request->validated('qty'));

        return $this->respond($request);
    }

    public function updateItem(UpdateCartItemRequest $request, string $variantUuid): CartResource
    {
        $cart = $this->service->forUser($request->user());
        $variant = ProductVariant::where('uuid', $variantUuid)->firstOrFail();
        $this->service->setItem($cart, $variant, (float) $request->validated('qty'));

        return $this->respond($request);
    }

    public function removeItem(Request $request, string $variantUuid): CartResource
    {
        $cart = $this->service->forUser($request->user());
        $variant = ProductVariant::where('uuid', $variantUuid)->firstOrFail();
        $this->service->removeItem($cart, $variant);

        return $this->respond($request);
    }

    public function clear(Request $request): CartResource
    {
        $this->service->clear($this->service->forUser($request->user()));

        return $this->respond($request);
    }

    private function respond(Request $request): CartResource
    {
        // نسخة حديثة: تفادي 201 التلقائي على GET حين تُنشأ السلة أوّل مرّة (wasRecentlyCreated).
        $cart = $this->service->forUser($request->user())->fresh('items.variant');

        return new CartResource($cart);
    }
}
