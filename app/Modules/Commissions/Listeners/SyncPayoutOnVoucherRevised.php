<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Accounting\Events\VoucherRevised;
use App\Modules\Commissions\Models\CommissionPayout;

/**
 * تعديلُ سند الصرف يُصلح دفعةَ العمولة المرتبطة به.
 *
 * ## العطب الذي يُغلقه
 *
 * الدفعة كانت تحتفظ بنسخةٍ من مبلغ السند لحظة الإنشاء. فإن عُدّل السند لاحقًا
 * — وهو مسارٌ مشروع: يُعكس القيد ويُرحَّل قيدٌ مُصحّح — بقيت النسخة على القيمة
 * القديمة. فيقول الدفتر ٨٬٣٧٧ ويقول أرشيف الدفعات ٧٬٣٣٧، ويُحسب الرصيد المتبقّي
 * على الرقم القديم فيظهر للمسوّق مستحقٌّ صُرف فعلًا.
 *
 * ولا يظهر ذلك خطأً في أي شاشة: رقمان كلاهما «صحيح» في مكانه.
 *
 * ## ولماذا لا يُكتفى بالقراءة من السند
 *
 * القراءة تُصلح الشاشة، والعمود يبقى كاذبًا في قاعدة البيانات — يقرؤه تصديرٌ أو
 * تقريرٌ أو استعلامٌ مباشر فيُعيد الخطأ من بابٍ آخر. فيُصحَّح الاثنان: العمود
 * يُزامَن هنا، والقراءة تُشتقّ من السند فلا تعتمد على نجاح المزامنة.
 */
class SyncPayoutOnVoucherRevised
{
    public function handle(VoucherRevised $event): void
    {
        $voucher = $event->voucher;

        CommissionPayout::where('financial_voucher_id', $voucher->id)
            ->get()
            ->each(function (CommissionPayout $payout) use ($voucher) {
                $payout->update([
                    'total' => round((float) $voucher->amount, 2),
                    // الخزينة قد تتغيّر في التعديل: من صُرف من البنك ثم صُحّح
                    // إلى الصندوق يجب أن يقرأ الأرشيفُ الصندوق.
                    'treasury_id' => $voucher->treasury_id ?? $payout->treasury_id,
                    'notes' => $voucher->notes ?? $payout->notes,
                ]);
            });
    }
}
