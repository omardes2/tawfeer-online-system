<?php

namespace App\Modules\Returns\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Sales\Models\OrderItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند طلب مرتجع (Phase 4.4 / ADR-040).
 */
class ReturnRequestItem extends Model
{
    protected $fillable = [
        'return_request_id', 'order_item_id', 'variant_id', 'qty', 'reason_code',
        'inspection_result', 'inventory_route', 'restocked',
        'exchange_variant_id', 'exchange_qty', 'price_difference',
        'unit_price_snapshot', 'wholesale_cost_snapshot',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
        'exchange_qty' => 'decimal:3',
        'price_difference' => 'decimal:2',
        'unit_price_snapshot' => 'decimal:2',
        'wholesale_cost_snapshot' => 'decimal:2',
        'restocked' => 'boolean',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function exchangeVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'exchange_variant_id');
    }
}
