<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة مخصّص نهاية الخدمة.
 *
 * دفترٌ لا عمود رصيد: الرصيد مجموعُ حركاته. فالتراكم موجبٌ والتصفية سالبة،
 * والجمع وحده يعطي ما على الشركة للموظف — لا رقمٌ محفوظ يُصحَّح فيفترق عن
 * القيود التي وُلِّد منها.
 */
class EndOfServiceEntry extends Model
{
    public const LABELS = [
        'accrual' => 'تراكم شهريّ',
        'settlement' => 'تصفية',
        'adjustment' => 'تسوية',
    ];

    protected $fillable = [
        'employee_profile_id', 'kind', 'entry_date', 'amount',
        'payroll_run_id', 'financial_voucher_id', 'note', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(FinancialVoucher::class, 'financial_voucher_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function label(): string
    {
        return self::LABELS[$this->kind] ?? $this->kind;
    }

    /**
     * المبلغ الفعليّ للحركة — **من السند حيث وُجد**.
     *
     * حركةُ التصفية نسخةٌ سالبة من مبلغ سند الصرف تُكتب لحظة الصرف. والسند
     * وثيقةٌ تُعدَّل بعدها (عكسٌ ثم قيد مُصحّح)، فتبقى النسخة على القيمة القديمة
     * ويفترق دفتر المخصّص عن الدفتر العامّ.
     *
     * **والسند المعكوس لا يُنقص المخصّص**: ماله عاد إلى الخزينة، فالالتزام قائم
     * كما كان. وتركُ الحركة سالبةً بعد العكس يُطفئ التزامًا لم يُدفع.
     */
    public function effectiveAmount(): float
    {
        if (! $this->voucher) {
            return round((float) $this->amount, 2);
        }

        if (in_array($this->voucher->status, ['reversed', 'cancelled', 'rejected'], true)) {
            return 0.0;
        }

        return round(-(float) $this->voucher->amount, 2);
    }
}
