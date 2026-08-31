<?php

namespace App\Modules\Hr\Listeners;

use App\Modules\Accounting\Events\VoucherRevised;
use App\Modules\Hr\Models\EndOfServiceEntry;

/**
 * تعديلُ سند صرفٍ يُصلح حركةَ تصفية نهاية الخدمة المرتبطة به.
 *
 * الحركة نسخةٌ سالبة من مبلغ السند، والقراءة تُشتقّ من السند
 * (`EndOfServiceEntry::effectiveAmount`) فلا تفترق. لكنّ العمود المحفوظ يبقى
 * قديمًا في قاعدة البيانات، يقرؤه تصديرٌ أو استعلامٌ مباشر فيُعيد الخطأ من باب
 * آخر — فيُزامَن هنا.
 */
class SyncEndOfServiceOnVoucherRevised
{
    public function handle(VoucherRevised $event): void
    {
        $voucher = $event->voucher;

        EndOfServiceEntry::where('financial_voucher_id', $voucher->id)
            ->update([
                'amount' => -round((float) $voucher->amount, 2),
                'entry_date' => $voucher->voucher_date,
            ]);
    }
}
