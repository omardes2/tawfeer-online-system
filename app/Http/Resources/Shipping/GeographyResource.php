<?php

namespace App\Http\Resources\Shipping;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * مورد مرجعي للجغرافيا (محافظة/مدينة/منطقة) — معرّف داخلي (ADR-002).
 */
class GeographyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'code' => $this->code,
            'governorate_id' => $this->governorate_id ?? null,
            'city_id' => $this->city_id ?? null,
            'is_active' => (bool) $this->is_active,
        ], fn ($v) => $v !== null);
    }
}
