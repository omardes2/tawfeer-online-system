<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تنسيق موحّد للمستودع (API-First، المبدأ 11). يكشف uuid لا id (ADR-002).
 */
class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'address' => $this->address,
            'phone' => $this->phone,
            'allow_negative' => $this->allow_negative,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch->uuid,
                'name' => $this->branch->name,
            ]),
            'locations_count' => $this->whenCounted('locations'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
