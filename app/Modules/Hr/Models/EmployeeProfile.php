<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * ملفّ الموظف — الوجه التوظيفيّ للمستخدم.
 *
 * كل موظفٍ مستخدم وليس كل مستخدمٍ موظفًا، فالملفّ يُنشأ لمن يُوظَّف فعلًا.
 */
class EmployeeProfile extends Model
{
    use Auditable, HasUuid, SoftDeletes;

    protected $fillable = [
        'user_id', 'branch_id', 'hire_date', 'end_date', 'status', 'employment_type',
        'annual_leave_days', 'national_id', 'bank_account', 'notes', 'created_by',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'end_date' => 'date',
        'annual_leave_days' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<EmployeeSalary, $this> */
    public function salaries(): HasMany
    {
        return $this->hasMany(EmployeeSalary::class)->orderByDesc('effective_from');
    }

    /** @return HasMany<EmployeeLeave, $this> */
    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class)->orderByDesc('from_date');
    }

    /** @return HasMany<PayrollLine, $this> */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /** @return HasMany<EndOfServiceEntry, $this> */
    public function endOfServiceEntries(): HasMany
    {
        return $this->hasMany(EndOfServiceEntry::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * الراتب الساري في تاريخٍ ما — أحدثُ صفٍّ تاريخُ سريانه ≤ ذلك التاريخ.
     *
     * فزيادةُ اليوم لا تُعيد كتابة مسيّر الشهر الماضي.
     */
    public function salaryOn(string $date): ?EmployeeSalary
    {
        return $this->salaries()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->first();
    }

    /** أشهر الخدمة حتى تاريخٍ ما — أساسُ مخصّص نهاية الخدمة. */
    public function serviceMonthsUntil(string $date): float
    {
        $end = $this->end_date && $this->end_date->lt($date) ? $this->end_date : Carbon::parse($date);

        return max(0, round($this->hire_date->floatDiffInMonths($end), 2));
    }
}
