<?php

namespace App\Modules\Catalog\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Catalog\BrandFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * العلامة التجارية (PHASE_2_DESIGN §12).
 */
class Brand extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = ['name', 'slug', 'logo', 'description', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** إبطال كاش تنقّل المتجر عند أي تغيير علامة (Production Readiness). */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('storefront:brands');
        static::saved($flush);
        static::deleted($flush);
    }

    protected static function newFactory(): Factory
    {
        return BrandFactory::new();
    }
}
