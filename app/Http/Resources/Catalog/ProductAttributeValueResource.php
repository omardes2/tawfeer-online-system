<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAttributeValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attribute_id' => $this->attribute_id,
            'value' => $this->value,
            'label' => $this->label,
            'color_hex' => $this->color_hex,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
        ];
    }
}
