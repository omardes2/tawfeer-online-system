<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use Database\Factories\Inventory\InventoryStockFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيد المخزون بالدلاء لكل (متغيّر × مستودع) — §22، ADR-007.
 * `available` = on_hand − reserved (محسوب، لا يُخزَّن).
 */
class InventoryStock extends Model
{
    use Auditable, HasFactory;

    protected $fillable = [
        'variant_id', 'warehouse_id', 'on_hand', 'reserved', 'damaged',
        'returned_pending', 'in_transit', 'average_cost', 'cost_price',
        'reorder_level', 'reorder_qty', 'last_movement_at',
    ];

    protected $casts = [
        'on_hand' => 'decimal:3',
        'reserved' => 'decimal:3',
        'damaged' => 'decimal:3',
        'returned_pending' => 'decimal:3',
        'in_transit' => 'decimal:3',
        'average_cost' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'reorder_level' => 'decimal:3',
        'reorder_qty' => 'decimal:3',
        'last_movement_at' => 'datetime',
    ];

    protected function available(): Attribute
    {
        return Attribute::make(get: fn () => (float) $this->on_hand - (float) $this->reserved);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    protected static function newFactory(): Factory
    {
        return InventoryStockFactory::new();
    }
}
