<?php

namespace App\Modules\Shipping\Models;

use App\Modules\Foundation\Models\DeliveryProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سجلّ استيعاب حدث مزوّد خام (ADR-039): webhook/مزامنة — تسجيل كامل لكل محاولة.
 */
class DeliveryProviderEvent extends Model
{
    protected $fillable = [
        'delivery_provider_id', 'provider_code', 'shipment_id', 'channel',
        'event_id', 'external_id', 'provider_status', 'signature_valid',
        'status', 'payload', 'error', 'received_at', 'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }
}
