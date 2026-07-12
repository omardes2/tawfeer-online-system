<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'number' => $this->number,
            'status' => $this->status,
            'amount' => $this->amount,
            'refunded_amount' => $this->refunded_amount,
            'currency' => $this->currency,
            'order' => $this->whenLoaded('order', fn () => [
                'id' => $this->order->uuid,
                'number' => $this->order->number,
                'payment_status' => $this->order->payment_status,
                'amount_paid' => $this->order->amount_paid,
            ]),
            'method' => $this->whenLoaded('method', fn () => ['code' => $this->method->code, 'name' => $this->method->name, 'type' => $this->method->type]),
            'provider_reference' => $this->provider_reference,
            'transactions' => $this->whenLoaded('transactions', fn () => PaymentTransactionResource::collection($this->transactions)),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
