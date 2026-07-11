<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Foundation\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * المستودع (PHASE_2_DESIGN §7؛ ADR-003 تعدد المستودعات، المبدأ 2).
 *
 * موقع مادّي للمخزون يتبع فرعًا واحدًا. المخزون يُمسك لكل (متغيّر × مستودع).
 * `allow_negative` يحكم السماح بالرصيد السالب لهذا المستودع (ADR-007a).
 */
class Warehouse extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'branch_id',
        'name',
        'code',
        'type',
        'governorate_id',
        'city_id',
        'address',
        'phone',
        'allow_negative',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'allow_negative' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    protected static function newFactory(): Factory
    {
        return WarehouseFactory::new();
    }
}
