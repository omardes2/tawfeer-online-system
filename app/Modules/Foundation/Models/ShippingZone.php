<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * منطقة شحن (PHASE_2_DESIGN §6، ADR-014). uuid + soft-delete + auditable. التسعير الفعلي مؤجّل (Phase 3).
 */
class ShippingZone extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = ['branch_id', 'name', 'code', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function cities(): BelongsToMany
    {
        return $this->belongsToMany(City::class, 'shipping_zone_city');
    }

    public function areas(): BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'shipping_zone_area');
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
