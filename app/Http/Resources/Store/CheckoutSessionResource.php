<?php

namespace App\Http\Resources\Store;

use App\Modules\Store\Services\CheckoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * حالة جلسة الإتمام: البيانات المتراكمة + ملخّص السلة + جاهزية الإتمام.
 */
class CheckoutSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'shipping_address' => $this->shipping_address,
            'city_id' => $this->city_id,
            'area_id' => $this->area_id,
            'payment_method' => $this->payment_method_code,
            'ready' => $this->isReady(),
            'cart' => $this->whenLoaded('cart', function () {
                $subtotal = round($this->cart->subtotal(), 2);
                // الرسوم تُحسب في الخلفية وتُعرَض كما هي — لا تُعاد المعادلة في الواجهة.
                $delivery = round(app(CheckoutService::class)->deliveryFee($this->resource, $subtotal), 2);

                return [
                    'item_count' => $this->cart->items->count(),
                    'subtotal' => $subtotal,
                    'delivery_fee' => $delivery,
                    'total' => round($subtotal + $delivery, 2),
                ];
            }),
        ];
    }
}
