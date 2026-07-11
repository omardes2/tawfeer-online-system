<?php

namespace App\Http\Resources\Purchasing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'legal_name' => $this->legal_name,
            'tax_number' => $this->tax_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'payment_terms_days' => $this->payment_terms_days,
            'credit_limit' => $this->credit_limit,
            'opening_balance' => $this->opening_balance,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'position' => $c->position,
                'email' => $c->email,
                'phone' => $c->phone,
                'is_primary' => (bool) $c->is_primary,
                'notes' => $c->notes,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
