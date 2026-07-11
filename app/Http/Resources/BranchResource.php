<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * تنسيق موحّد للفرع (API-First). يكشف uuid لا id (ADR-002).
 */
class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'tax_number' => $this->tax_number,
            'timezone' => $this->timezone,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'default_warehouse' => $this->whenLoaded('defaultWarehouse', fn () => $this->defaultWarehouse ? [
                'id' => $this->defaultWarehouse->uuid,
                'name' => $this->defaultWarehouse->name,
            ] : null),
            'warehouses_count' => $this->whenCounted('warehouses'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
