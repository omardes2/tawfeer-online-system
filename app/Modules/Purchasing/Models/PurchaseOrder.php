<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Purchasing\PurchaseOrderFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * أمر شراء (DATABASE_DESIGN §4، ADR-025).
 * draft → pending_approval → approved → partially_received → received → closed (+cancelled).
 */
class PurchaseOrder extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'supplier_id', 'warehouse_id', 'branch_id', 'status',
        'order_date', 'expected_date', 'subtotal', 'discount_total', 'total',
        'notes', 'created_by', 'approved_by', 'approved_at',
    ];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'approved_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    protected static function newFactory(): Factory
    {
        return PurchaseOrderFactory::new();
    }
}
