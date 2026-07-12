<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * مدينة (PHASE_2_DESIGN §4، ADR-014). مرجعية داخلية بلا uuid/soft-delete.
 */
class City extends Model
{
    protected $fillable = ['governorate_id', 'name', 'name_en', 'code', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    public function shippingZones(): BelongsToMany
    {
        return $this->belongsToMany(ShippingZone::class, 'shipping_zone_city');
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
