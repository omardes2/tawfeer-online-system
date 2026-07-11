<?php

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\UnitFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * وحدة القياس مع تحويل بين الوحدات (PHASE_2_DESIGN §13). مرجعي: لا uuid/soft-delete.
 */
class Unit extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_en', 'code', 'symbol', 'base_unit_id', 'conversion_factor', 'is_active',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'is_active' => 'boolean',
    ];

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_unit_id');
    }

    public function derivedUnits(): HasMany
    {
        return $this->hasMany(self::class, 'base_unit_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return UnitFactory::new();
    }
}
