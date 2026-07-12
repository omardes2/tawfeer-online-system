<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'primary_phone' => $this->primary_phone,
            'source' => $this->source,
            'category' => $this->category,
            'tags' => $this->tags ?? [],
            'credit_limit' => $this->credit_limit,
            'loyalty_points' => $this->loyalty_points,
            'is_high_risk' => (bool) $this->is_high_risk,
            'is_blocked' => (bool) $this->is_blocked,
            'blocked_reason' => $this->blocked_reason,
            'cancelled_orders_count' => $this->cancelled_orders_count,
            'returns_count' => $this->returns_count,
            'notes_text' => $this->notes,
            'phones' => $this->whenLoaded('phones', fn () => $this->phones->map(fn ($p) => [
                'id' => $p->id, 'phone' => $p->phone, 'label' => $p->label, 'is_primary' => (bool) $p->is_primary,
            ])),
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label, 'recipient_name' => $a->recipient_name, 'phone' => $a->phone,
                'governorate_id' => $a->governorate_id, 'city_id' => $a->city_id, 'area_id' => $a->area_id,
                'address_line' => $a->address_line, 'is_default' => (bool) $a->is_default,
            ])),
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'position' => $c->position,
                'email' => $c->email, 'phone' => $c->phone, 'is_primary' => (bool) $c->is_primary,
            ])),
            'notes' => $this->whenLoaded('customerNotes', fn () => $this->customerNotes->map(fn ($n) => [
                'body' => $n->body, 'created_by' => $n->author?->name, 'created_at' => $n->created_at?->toIso8601String(),
            ])),
            'orders_count' => $this->whenCounted('orders'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
