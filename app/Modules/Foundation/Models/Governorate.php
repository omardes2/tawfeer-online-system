<?php

namespace App\Modules\Foundation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * محافظة (PHASE_2_DESIGN §3، ADR-014). مرجعية داخلية بلا uuid/soft-delete.
 */
class Governorate extends Model
{
    protected $fillable = ['name', 'name_en', 'code', 'country_code', 'sort_order', 'is_active'];

    protected $casts = ['sort_order' => 'integer', 'is_active' => 'boolean'];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
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
