<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;

class ProductAttributeValueService
{
    public function create(ProductAttribute $attribute, array $data): ProductAttributeValue
    {
        return $attribute->values()->create($data);
    }

    public function update(ProductAttributeValue $value, array $data): ProductAttributeValue
    {
        $value->update($data);

        return $value;
    }

    public function delete(ProductAttributeValue $value): void
    {
        $value->delete();
    }
}
