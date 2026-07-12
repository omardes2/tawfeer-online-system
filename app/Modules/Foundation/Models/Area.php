<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * منطقة (PHASE_2_DESIGN §5، ADR-014). مرجعية داخلية بلا uuid/soft-delete.
 */
class Area extends Model
{
    protected $fillable = ['city_id', 'name', 'name_en', 'code', 'postal_code', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    public function shippingZones(): BelongsToMany
    {
        return $this->belongsToMany(ShippingZone::class, 'shipping_zone_area');
    }

    public function providerMappings(): MorphMany
    {
        return $this->morphMany(GeoProviderMapping::class, 'mappable');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
