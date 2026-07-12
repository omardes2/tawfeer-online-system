<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->kind,
            'status' => $this->status,
            'amount' => $this->amount,
            'provider_reference' => $this->provider_reference,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
