<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'is_postable' => (bool) $this->is_postable,
            'is_active' => (bool) $this->is_active,
            'parent_code' => $this->whenLoaded('parent', fn () => $this->parent?->code),
        ];
    }
}
