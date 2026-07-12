<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * سجلّ شركة توصيل (ADR-027). يُوصَل حصريًا عبر طبقة التكامل (المبدأ 13).
 */
class DeliveryProvider extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = ['name', 'code', 'driver', 'is_active', 'config'];

    protected $casts = ['is_active' => 'boolean', 'config' => 'array'];

    public function mappings(): HasMany
    {
        return $this->hasMany(GeoProviderMapping::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
