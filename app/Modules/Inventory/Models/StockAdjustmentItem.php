<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند تسوية الجرد (§26). qty_diff = qty_counted − qty_before.
 */
class StockAdjustmentItem extends Model
{
    protected $fillable = [
        'stock_adjustment_id', 'variant_id', 'qty_before', 'qty_counted', 'qty_diff', 'unit_cost', 'note',
    ];

    protected $casts = [
        'qty_before' => 'decimal:3',
        'qty_counted' => 'decimal:3',
        'qty_diff' => 'decimal:3',
        'unit_cost' => 'decimal:4',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
