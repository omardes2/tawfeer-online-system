<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * حركة مكافأةٍ أو سلفة — خارج عقد الراتب.
 *
 * المبلغ موجبٌ دائمًا والاتجاه من `kind`: دفترٌ فيه سالبٌ وموجب لنوعين
 * مختلفين يُغري بجمعٍ لا معنى له — مكافأةٌ ناقص سلفة ليست رقمًا.
 */
class EmployeeFinanceEntry extends Model
{
    public const LABELS = [
        'bonus' => 'مكافأة',
        'advance' => 'سلفة',
        'advance_repayment' => 'تسديد سلفة',
    ];

    protected $fillable = [
        'employee_profile_id', 'kind', 'entry_date', 'amount',
        'financial_voucher_id', 'note', 'created_by',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeeProfile::class, 'employee_profile_id');
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
     * المبلغ الفعليّ — **من السند حيث وُجد**.
     *
     * الحركة نسخةٌ من مبلغ سندها تُكتب لحظة التسجيل، والسند يُعدَّل بعدها
     * (عكسٌ ثم قيدٌ مُصحّح) فتبقى النسخة قديمة ويفترق الدفتران.
     *
     * **والسند المعكوس لا يُحتسب**: ماله عاد إلى الخزينة — فلا سلفةَ قائمة
     * ولا مكافأةَ صُرفت.
     */
    public function effectiveAmount(): float
    {
        if (! $this->voucher) {
            return round((float) $this->amount, 2);
        }

        if (in_array($this->voucher->status, ['reversed', 'cancelled', 'rejected'], true)) {
            return 0.0;
        }

        return round((float) $this->voucher->amount, 2);
    }

    /** أثرُ الحركة على الدَّين: السلفة تزيده والتسديد يُنقصه. */
    public function advanceEffect(): float
    {
        return match ($this->kind) {
            'advance' => $this->effectiveAmount(),
            'advance_repayment' => -$this->effectiveAmount(),
            default => 0.0,
        };
    }
}
