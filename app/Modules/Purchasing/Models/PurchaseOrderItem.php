<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'variant_id', 'qty_ordered', 'unit_cost', 'discount', 'line_total', 'qty_received',
    ];

    protected $casts = [
        'qty_ordered' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'discount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'qty_received' => 'decimal:3',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
