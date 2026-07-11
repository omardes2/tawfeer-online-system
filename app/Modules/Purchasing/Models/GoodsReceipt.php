<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Purchasing\GoodsReceiptFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * إيصال استلام بضاعة (GRN — ADR-025). draft → posted (+cancelled).
 */
class GoodsReceipt extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'number', 'purchase_order_id', 'warehouse_id', 'branch_id', 'status',
        'additional_cost', 'received_by', 'received_at', 'posted_at', 'notes', 'created_by',
    ];

    protected $casts = [
        'additional_cost' => 'decimal:4',
        'received_at' => 'datetime',
        'posted_at' => 'datetime',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    protected static function newFactory(): Factory
    {
        return GoodsReceiptFactory::new();
    }
}
