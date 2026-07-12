<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * تعيين كيان جغرافي محلي إلى مزوّد خارجي (ADR-027). المحلي مرجع النظام؛ تعدّد مزوّدين لكل سجلّ.
 */
class GeoProviderMapping extends Model
{
    protected $fillable = [
        'mappable_type', 'mappable_id', 'delivery_provider_id',
        'external_id', 'external_code', 'provider_metadata',
        'last_synced_at', 'sync_status', 'is_active',
    ];

    protected $casts = [
        'provider_metadata' => 'array',
        'last_synced_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
