<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * راتبٌ بتاريخ سريان.
 *
 * صفوفٌ لا عمود: الساري لشهرٍ ما هو أحدثُ صفٍّ سريانه ≤ نهاية ذلك الشهر، فلا
 * تُعيد الزيادةُ كتابةَ رواتب السنة الماضية.
 */
class EmployeeSalary extends Model
{
    use Auditable;

    protected $fillable = [
        'employee_profile_id', 'effective_from', 'basic_salary', 'allowances', 'note', 'created_by',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** الإجماليّ الشهريّ: الأساسيّ والبدلات. */
    public function gross(): float
    {
        return round((float) $this->basic_salary + (float) $this->allowances, 2);
    }
}
