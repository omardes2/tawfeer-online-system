<?php

namespace App\Modules\Foundation\Models;

use Database\Factories\Foundation\WarehouseLocationFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موقع تخزين داخل المستودع — شجري (PHASE_2_DESIGN §8).
 *
 * تقسيم داخلي (zone/rack/shelf/bin) لتحديد موضع الصنف.
 */
class WarehouseLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'parent_id',
        'code',
        'name',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    protected static function newFactory(): Factory
    {
        return WarehouseLocationFactory::new();
    }
}
