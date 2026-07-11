<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * دفتر المخزون append-only (§23، ADR-008/020). created_at فقط — لا updated_at، لا حذف.
 */
class InventoryLedger extends Model
{
    const UPDATED_AT = null;

    protected $table = 'inventory_ledger';

    protected $fillable = [
        'variant_id', 'warehouse_id', 'movement_id', 'branch_id', 'movement_type',
        'bucket', 'reference_type', 'reference_id', 'qty_change', 'balance_after',
        'unit_cost', 'wac_after', 'value_change', 'balance_value_after', 'created_by',
    ];

    protected $casts = [
        'qty_change' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'wac_after' => 'decimal:4',
        'value_change' => 'decimal:4',
        'balance_value_after' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'movement_id');
    }
}
