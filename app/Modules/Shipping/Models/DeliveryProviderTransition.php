<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Foundation\Models\DeliveryProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * انتقال حالة مزوّد خام (ADR-038). append-only، منفصل تمامًا عن السجلّ القانوني.
 */
class DeliveryProviderTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id', 'delivery_provider_id', 'from_provider_status', 'to_provider_status',
        'canonical_status', 'event_id', 'payload', 'received_at', 'created_at',
    ];

    protected $casts = ['payload' => 'array', 'received_at' => 'datetime', 'created_at' => 'datetime'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }
}
