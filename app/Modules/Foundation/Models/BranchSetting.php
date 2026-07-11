<?php

namespace App\Modules\Foundation\Models;

use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إعداد على مستوى الفرع يكمّل جدول settings العام (PHASE_2_DESIGN §2، المبدأ 9).
 *
 * فرادة مركّبة (branch_id, key) — لا مفتاح عام يمنع تعدد المستأجرين لاحقًا (ADR-004).
 */
class BranchSetting extends Model
{
    use Auditable;

    protected $fillable = [
        'branch_id',
        'key',
        'value',
        'group',
        'type',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
