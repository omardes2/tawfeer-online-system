<?php

namespace App\Http\Resources\Store;

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
            'payment_method' => $this->payment_method_code,
            'ready' => $this->isReady(),
            'cart' => $this->whenLoaded('cart', fn () => [
                'item_count' => $this->cart->items->count(),
                'subtotal' => round($this->cart->subtotal(), 2),
            ]),
        ];
    }
}
