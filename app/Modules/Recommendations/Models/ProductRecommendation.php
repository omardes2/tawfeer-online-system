<?php

namespace App\Modules\Recommendations\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * توصية منتج يدوية أو استثناء (Phase 6 / ADR-045).
 */
class ProductRecommendation extends Model
{
    protected $fillable = [
        'product_id', 'recommended_product_id', 'type', 'kind', 'position', 'is_active', 'created_by',
    ];

    protected $casts = ['is_active' => 'boolean', 'position' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recommended(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'recommended_product_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
