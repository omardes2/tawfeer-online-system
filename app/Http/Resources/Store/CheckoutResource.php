<?php

namespace App\Http\Resources\Store;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تأكيد إتمام الشراء: ملخّص الطلب المُنشأ والدفعة المبدوءة (يلفّ Order).
 */
class CheckoutResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $payment = $this->whenLoaded('payments', fn () => $this->payments->last());

        return [
            'order_number' => $this->number,
            'order_id' => $this->uuid,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'total' => $this->total,
            'item_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'payment' => $payment ? [
                'number' => $payment->number,
                'status' => $payment->status,
                'amount' => $payment->amount,
            ] : null,
        ];
    }
}
