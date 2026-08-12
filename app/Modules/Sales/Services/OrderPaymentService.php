<?php

namespace App\Modules\Sales\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Foundation\Services\Settings;
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

    /**
     * إدخال تحصيل COD إلى **«صندوق الأونلاين»** عبر سند قبض مُرحّل:
     * مدين صندوق الأونلاين / دائن «ذمم شركة التوصيل 1050» بكامل إجمالي الطلب —
     * فيصفَّر رصيد الطلب على 1050 ويظهر المبلغ رصيدًا في الصندوق.
     *
     * تُستدعى فقط بعد أن يُرجِع markCollected() صحيحًا (انتقال فعلي غير مدفوع → مدفوع)،
     * وهذا يستثني تلقائيًا الطلبات المدفوعة إلكترونيًا مسبقًا (لم يقبض المندوب شيئًا)
     * ويمنع الازدواج عند تكرار الحدث. تحويل المبلغ لاحقًا من الصندوق إلى البنك/الصندوق
     * الرئيسي (بعد خصم أجور الشركة) يقع في تسوية التوصيل.
     */
    public function collectCodToTreasury(Order $order): ?FinancialVoucher
    {
        $treasury = self::codTreasury();
        if ($treasury === null) {
            return null; // لا خزينة مُهيّأة — تُسجَّل الحالة «مدفوع» ويبقى المبلغ على 1050.
        }

        $code = $this->posting->receivableAccountCode($order);
        $account = $code ? Account::where('code', $code)->first() : null;
        if ($account === null) {
            return null;
        }

        // نفس أساس قيد البيع: قيمة البضاعة بلا رسوم التوصيل — فتُقفَل ذمّة الطلب على
        // «ذمم شركة التوصيل» تمامًا (لو حُصّل الإجمالي لصار الحساب دائنًا بقيمة التوصيل).
        $bookable = $this->posting->bookableTotal($order);
        if ($bookable <= 0) {
            return null;
        }

        $voucher = $this->vouchers->create('receipt', [
            'treasury_id' => $treasury->id,
            'amount' => $bookable,
            'counter_account_id' => $account->id,
            'customer_id' => $order->customer_id,
            'reference' => $order->number,
            'description' => __('تحصيل COD من شركة التوصيل — طلب :n', ['n' => $order->number]),
            'voucher_date' => now()->toDateString(),
        ]);
        $this->vouchers->approve($voucher);
        $this->vouchers->post($voucher);

        return $voucher;
    }

    /**
     * خزينة تحصيلات COD: المحدَّدة في الإعدادات (delivery.cod_treasury_id)، وإلا
     * «صندوق الأونلاين» (CB-ONLINE)، وإلا أي خزينة اسمها يحوي «اونلاين/أونلاين».
     */
    public static function codTreasury(): ?Treasury
    {
        $base = fn () => Treasury::query()->active()->whereNotNull('gl_account_id');

        $id = Settings::get('delivery.cod_treasury_id');
        if ($id && ($t = $base()->find((int) $id))) {
            return $t;
        }

        return $base()->where('code', 'CB-ONLINE')->first()
            // خزينة قائمة على حساب «صندوق الأونلاين» 1011-0002 (أنشأها المستخدم يدويًا بأي رمز/اسم).
            ?? $base()->whereHas('glAccount', fn ($q) => $q->where('code', '1011-0002'))->first()
            ?? $base()->where(fn ($q) => $q->where('name', 'like', '%أونلاين%')->orWhere('name', 'like', '%اونلاين%'))->first();
    }
}
