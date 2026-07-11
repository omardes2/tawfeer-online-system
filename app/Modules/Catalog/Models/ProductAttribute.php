<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductAttributeFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * سمة المنتج — محور تنويع (PHASE_2_DESIGN §18). مرجعي: لا uuid/soft-delete.
 */
class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'type', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function values(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class, 'attribute_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return ProductAttributeFactory::new();
    }
}
