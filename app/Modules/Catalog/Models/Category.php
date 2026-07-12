<?php

namespace App\Modules\Catalog\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Catalog\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;

/**
 * الفئة — تصنيف شجري للمنتجات (PHASE_2_DESIGN §11).
 */
class Category extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'parent_id', 'name', 'slug', 'description', 'image', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** إبطال كاش تنقّل المتجر عند أي تغيير فئة (Production Readiness). */
    protected static function booted(): void
    {
        $flush = fn () => Cache::forget('storefront:categories');
        static::saved($flush);
        static::deleted($flush);
    }

    protected static function newFactory(): Factory
    {
        return CategoryFactory::new();
    }
}
