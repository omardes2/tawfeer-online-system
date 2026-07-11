<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductAttributeValueFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * قيمة سمة (PHASE_2_DESIGN §19). فرادة مركّبة (attribute_id, value).
 */
class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = ['attribute_id', 'value', 'label', 'color_hex', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class, 'attribute_id');
    }

    protected static function newFactory(): Factory
    {
        return ProductAttributeValueFactory::new();
    }
}
