<?php

namespace App\Modules\Hr\Models;

use App\Modules\Accounting\Models\FinancialVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * بند كشف — راتب موظفٍ في شهر.
 *
 * أرقامه لقطاتٌ مُجمَّدة لا مراجعُ تُقرأ عند العرض: الكشف المُرحَّل مستندٌ
 * محاسبيّ، وقيمتُه يجب ألّا تتحرّك بعد ترحيله مهما تغيّر العقد.
 */
class PayrollLine extends Model
{
    protected $fillable = [
        'payroll_run_id', 'employee_profile_id',
        'basic_salary', 'allowances', 'other_additions',
        'unpaid_leave_days', 'unpaid_leave_amount', 'other_deductions',
        'net', 'eos_provision', 'financial_voucher_id', 'note',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'allowances' => 'decimal:2',
        'other_additions' => 'decimal:2',
        'unpaid_leave_days' => 'decimal:2',
        'unpaid_leave_amount' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net' => 'decimal:2',
        'eos_provision' => 'decimal:2',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(FinancialVoucher::class, 'financial_voucher_id');
    }

    public function earnings(): float
    {
        return round((float) $this->basic_salary + (float) $this->allowances + (float) $this->other_additions, 2);
    }

    public function deductions(): float
    {
        return round((float) $this->unpaid_leave_amount + (float) $this->other_deductions, 2);
    }

    public function isPaid(): bool
    {
        return $this->financial_voucher_id !== null;
    }
}
