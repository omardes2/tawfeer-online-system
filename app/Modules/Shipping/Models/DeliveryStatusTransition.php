<?php

namespace App\Modules\Shipping\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * انتقال في دورة الحياة القانونية للتوصيل (ADR-038). append-only: بلا updated_at ولا حذف.
 */
class DeliveryStatusTransition extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shipment_id', 'from_status', 'to_status', 'actor_type', 'actor_id',
        'reason_code', 'note', 'provider_status', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
