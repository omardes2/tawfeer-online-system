<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Database\Factories\Foundation\BranchFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * الفرع (المبدأ 1 في ARCHITECTURE.md: Multi-Branch Ready).
 *
 * نبدأ بفرع افتراضي واحد، لكن كل الكيانات التشغيلية تشير إلى branch_id
 * حتى لا نحتاج إعادة تصميم عند تفعيل تعدد الفروع.
 */
class Branch extends Model
{
    use Auditable, HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'address',
        'phone',
        'email',
        'tax_number',
        'default_currency_id',
        'default_warehouse_id',
        'timezone',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * الفرع الافتراضي — نقطة مرجعية وحيدة بدل أي قيمة ثابتة في الكود.
     */
    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function settings(): HasMany
    {
        return $this->hasMany(BranchSetting::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }

    protected static function newFactory(): Factory
    {
        return BranchFactory::new();
    }
}
