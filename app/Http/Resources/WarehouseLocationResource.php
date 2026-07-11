<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * موقع تخزين داخل المستودع (شجري). لا uuid (مرجعي داخلي — ADR-002) → يُكشف id.
 */
class WarehouseLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'is_active' => $this->is_active,
            'parent_id' => $this->parent_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => [
                'id' => $this->warehouse->uuid,
                'name' => $this->warehouse->name,
            ]),
            'children' => WarehouseLocationResource::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
