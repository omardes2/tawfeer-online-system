<?php

namespace App\Http\Controllers\Api\V1\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Http\Resources\Store\CheckoutResource;
use App\Modules\Store\Services\CheckoutService;

/**
 * إتمام الشراء للمستخدم الحالي (self-scoped): تحويل السلة النشطة إلى طلب.
 */
class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutService $service) {}

    public function store(CheckoutRequest $request): CheckoutResource
    {
        $order = $this->service->checkout($request->user(), $request->validated());

        return new CheckoutResource($order);
    }
}
