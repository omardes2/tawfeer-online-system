<?php

namespace App\Modules\Store\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند سلة (ADR-031). سعر البند لقطة من الكتالوج وقت الإضافة.
 */
class CartItem extends Model
{
    protected $fillable = ['cart_id', 'variant_id', 'qty', 'unit_price'];

    protected $casts = ['qty' => 'decimal:3', 'unit_price' => 'decimal:2'];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
