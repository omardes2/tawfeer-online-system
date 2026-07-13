<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * سعر توصيل مدينة لدى مزوّد (نمط Opost). المدن تُزامَن من المزوّد؛ الأسعار تُدار محليًا.
 */
class DeliveryCityRate extends Model
{
    protected $fillable = [
        'delivery_provider_id', 'city_id', 'external_code', 'name', 'name_en',
        'delivery_fee', 'return_fee', 'currency', 'is_active', 'synced_at',
    ];

    protected $casts = [
        'delivery_fee' => 'decimal:2',
        'return_fee' => 'decimal:2',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }
}
