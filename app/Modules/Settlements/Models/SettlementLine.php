<?php

namespace App\Modules\Settlements\Models;

use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سطر تسوية مالية (Phase 4.6 / ADR-041).
 */
class SettlementLine extends Model
{
    protected $fillable = [
        'delivery_settlement_id', 'order_id', 'shipment_id',
        'cod_amount', 'fee_amount', 'deduction_amount', 'net_amount',
        'reported_cod', 'matched', 'variance', 'note',
    ];

    protected $casts = [
        'cod_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'deduction_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'reported_cod' => 'decimal:2',
        'variance' => 'decimal:2',
        'matched' => 'boolean',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(DeliverySettlement::class, 'delivery_settlement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
