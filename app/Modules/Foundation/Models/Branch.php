<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * الفرع (المبدأ 1 في ARCHITECTURE.md: Multi-Branch Ready).
 *
 * نبدأ بفرع افتراضي واحد، لكن كل الكيانات التشغيلية تشير إلى branch_id
 * حتى لا نحتاج إعادة تصميم عند تفعيل تعدد الفروع.
 */
class Branch extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'code',
        'address',
        'phone',
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
}
