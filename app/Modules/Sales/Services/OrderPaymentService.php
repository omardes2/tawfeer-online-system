<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تحصيل مبالغ طلبات البيع (كامل/جزئي) إلى خزينة/بنك محدَّد عبر **سند قبض** موحّد.
 *
 * يُرحّل قيدًا متوازنًا (مدين الخزينة / دائن حساب مديونية الطلب — حساب العميل الفرعي أو 1100)
 * فيُقفل المديونية ويزيد رصيد الخزينة، ثم يُحدّث حالة دفع الطلب. لا مساس مباشر بالأرصدة.
 */
class OrderPaymentService
{
    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly SalesPostingService $posting,
    ) {}

    public function collect(Order $order, int $treasuryId, float $amount, ?string $date = null): FinancialVoucher
    {
        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => __('لا يمكن تحصيل دفعة على طلب ملغى.')]);
        }

        $amount = round($amount, 2);
        $outstanding = round((float) $order->total - (float) $order->amount_paid, 2);
        if ($amount <= 0 || $amount > $outstanding + 0.001) {
            throw ValidationException::withMessages([
                'amount' => __('المبلغ يجب أن يكون بين 0 والمتبقّي (:due).', ['due' => number_format($outstanding, 2)]),
            ]);
        }

        $treasury = Treasury::where('id', $treasuryId)->where('is_active', true)->first();
        if (! $treasury || ! $treasury->gl_account_id) {
            throw ValidationException::withMessages(['treasury_id' => __('اختر خزينة/حسابًا بنكيًا صالحًا.')]);
        }

        $code = $this->posting->receivableAccountCode($order);
        $account = $code ? Account::where('code', $code)->first() : null;
        if (! $account) {
            throw ValidationException::withMessages(['order' => __('لا يوجد حساب مديونية صالح للطلب.')]);
        }

        return DB::transaction(function () use ($order, $treasury, $amount, $account, $date) {
            $voucher = $this->vouchers->create('receipt', [
                'treasury_id' => $treasury->id,
                'amount' => $amount,
                'counter_account_id' => $account->id,
                'customer_id' => $order->customer_id,
                'reference' => $order->number,
                'description' => __('تحصيل طلب :n', ['n' => $order->number]),
                'voucher_date' => $date ?? now()->toDateString(),
            ]);
            $this->vouchers->approve($voucher);
            $this->vouchers->post($voucher);

            $paid = round((float) $order->amount_paid + $amount, 2);
            $order->update([
                'amount_paid' => $paid,
                'payment_status' => $paid + 0.001 >= (float) $order->total ? 'paid' : 'partially_paid',
            ]);

            return $voucher;
        });
    }

    /**
     * تعليم طلب توصيل بأنه **محصَّل من العميل** (المندوب قبض ثمنه) — دون قيد محاسبي جديد.
     *
     * طلبات التوصيل تُرحَّل عند البيع على «ذمم شركة التوصيل 1050» لا على ذمم العميل، فالمبلغ
     * مُثبَت أصلًا كذمّة على شركة التوصيل؛ ما يتغيّر عند التحصيل هو **واقعة قبض العميل** فقط.
     * إقفال 1050 يقع لاحقًا في تسوية التوصيل (مدين الخزينة + الرسوم / دائن 1050) — فأي قيد
     * هنا يعني ازدواجًا. لذلك تُحدَّث الحقول فقط (والتغيير مسجَّل في Audit Log).
     *
     * idempotent: الطلب المسدَّد أو الملغى أو الصفري لا يتأثّر.
     *
     * @return bool هل تغيّرت الحالة فعلًا
     */
    public function markCollected(Order $order): bool
    {
        if ($order->status === 'cancelled' || $order->payment_status === 'paid') {
            return false;
        }

        if ($order->channel === 'pos') {
            throw ValidationException::withMessages([
                'order' => __('المبيعات المباشرة تُسدَّد عبر «دفع» باختيار الخزينة (يلزمها سند قبض).'),
            ]);
        }

        $total = round((float) $order->total, 2);
        if ($total <= 0) {
            return false;
        }

        $order->update(['amount_paid' => $total, 'payment_status' => 'paid']);

        return true;
    }
}
