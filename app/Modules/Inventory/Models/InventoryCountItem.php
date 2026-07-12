<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند جرد مخزون (Phase 5 / ADR-043).
 */
class InventoryCountItem extends Model
{
    protected $fillable = [
        'inventory_count_id', 'variant_id', 'system_qty', 'counted_qty', 'variance', 'applied', 'note', 'counted_at',
    ];

    protected $casts = [
        'system_qty' => 'decimal:3',
        'counted_qty' => 'decimal:3',
        'variance' => 'decimal:3',
        'applied' => 'boolean',
        'counted_at' => 'datetime',
    ];

    public function count(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'inventory_count_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
