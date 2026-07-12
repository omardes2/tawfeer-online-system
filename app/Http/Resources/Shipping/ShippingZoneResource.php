<?php

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'is_active' => (bool) $this->is_active,
            'cities' => $this->whenLoaded('cities', fn () => $this->cities->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])),
            'areas' => $this->whenLoaded('areas', fn () => $this->areas->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])),
        ];
    }
}
