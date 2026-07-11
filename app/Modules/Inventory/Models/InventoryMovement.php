<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * حركة مخزون — سجلّ ثابت (§24، ADR-008). لا soft-delete ولا تعديل.
 */
class InventoryMovement extends Model
{
    use HasUuid;

    protected $fillable = [
        'branch_id', 'variant_id', 'warehouse_id', 'to_warehouse_id', 'type', 'bucket',
        'qty', 'unit_cost', 'total_cost', 'reference_type', 'reference_id',
        'reason', 'note', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(InventoryLedger::class, 'movement_id');
    }
}
