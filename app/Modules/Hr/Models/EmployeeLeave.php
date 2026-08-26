<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * إجازة.
 *
 * ثلاثة أنواعٍ بأثرين مختلفين: **السنوية** تُنقص الرصيد ولا تمسّ الراتب،
 * و**غير المدفوعة** تُخصَم من راتب شهرها ولا تمسّ الرصيد، و**المرضية** لا
 * تفعل أيًّا منهما — تُسجَّل للسجلّ.
 */
class EmployeeLeave extends Model
{
    use Auditable;

    public const KINDS = ['annual', 'unpaid', 'sick'];

    public const LABELS = [
        'annual' => 'سنوية',
        'unpaid' => 'بلا راتب',
        'sick' => 'مرضية',
    ];

    protected $fillable = [
        'employee_profile_id', 'kind', 'from_date', 'to_date', 'days', 'reason', 'created_by',
    ];

    protected $casts = [
        'from_date' => 'date',
        'to_date' => 'date',
        'days' => 'decimal:2',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeKind(Builder $query, string $kind): Builder
    {
        return $query->where('kind', $kind);
    }

    /** إجازات سنةٍ ميلادية — الرصيد يُحسب على السنة لا على مدّة الخدمة. */
    public function scopeInYear(Builder $query, int $year): Builder
    {
        return $query->whereYear('from_date', $year);
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }
}
