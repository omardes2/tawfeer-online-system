<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ تغيير سعر بيع يدوي (Phase 4.1 / ADR-037) — **غير قابل للتعديل** (append-only).
 * كل تعديل حركة موثّقة بحالة اعتماد (BR-EMP-05/BR-PRICE-06). لا حذف — المبدأ 8.
 */
class OrderPriceChange extends Model
{
    protected $fillable = [
        'order_id', 'order_item_id', 'variant_id',
        'original_retail', 'wholesale_cost', 'min_price', 'new_price', 'discount',
        'reason', 'requires_approval', 'status', 'requested_by', 'approved_by', 'decided_at',
    ];

    protected $casts = [
        'original_retail' => 'decimal:2',
        'wholesale_cost' => 'decimal:2',
        'min_price' => 'decimal:2',
        'new_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'requires_approval' => 'boolean',
        'decided_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
