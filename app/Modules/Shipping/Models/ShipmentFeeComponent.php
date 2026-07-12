<?php

namespace App\Modules\Shipping\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مكوّن رسم شحنة (ADR-039) — مستقلّ عن المزوّد.
 */
class ShipmentFeeComponent extends Model
{
    protected $fillable = ['shipment_id', 'type', 'amount', 'owner', 'source', 'note', 'created_by'];

    protected $casts = ['amount' => 'decimal:2'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
