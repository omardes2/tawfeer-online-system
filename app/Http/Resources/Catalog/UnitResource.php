<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'code' => $this->code,
            'symbol' => $this->symbol,
            'conversion_factor' => $this->conversion_factor,
            'is_active' => $this->is_active,
            'base_unit' => $this->whenLoaded('baseUnit', fn () => $this->baseUnit ? [
                'id' => $this->baseUnit->id,
                'name' => $this->baseUnit->name,
                'code' => $this->baseUnit->code,
            ] : null),
        ];
    }
}
