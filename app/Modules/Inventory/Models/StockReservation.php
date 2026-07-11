<?php

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Inventory\StockReservationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حجز مخزون (§25، ADR-009). دورة الحياة عبر status: active/released/consumed/expired.
 */
class StockReservation extends Model
{
    use Auditable, HasFactory, HasUuid;

    protected $fillable = [
        'variant_id', 'warehouse_id', 'order_id', 'order_item_id',
        'reference_type', 'reference_id', 'qty', 'status',
        'reserved_at', 'expires_at', 'released_at', 'created_by',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    protected static function newFactory(): Factory
    {
        return StockReservationFactory::new();
    }
}
