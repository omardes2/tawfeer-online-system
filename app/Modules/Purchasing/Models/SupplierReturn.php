<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Purchasing\SupplierReturnFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * مرتجع مشتريات (BR-PUR-11، ADR-025). draft → approved → posted (+cancelled).
 */
class SupplierReturn extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'supplier_id', 'warehouse_id', 'purchase_order_id', 'branch_id',
        'status', 'reason', 'notes', 'created_by', 'approved_by', 'approved_at', 'posted_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SupplierReturnItem::class);
    }

    protected static function newFactory(): Factory
    {
        return SupplierReturnFactory::new();
    }
}
