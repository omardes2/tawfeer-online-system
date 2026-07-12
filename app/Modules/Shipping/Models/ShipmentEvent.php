<?php

namespace App\Modules\Shipping\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حدث تتبّع شحنة (ADR-027، BR-ORD-09/10). append-only: بلا updated_at.
 */
class ShipmentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['shipment_id', 'from_status', 'to_status', 'source', 'note', 'provider_payload', 'changed_by', 'created_at'];

    protected $casts = ['provider_payload' => 'array', 'created_at' => 'datetime'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
