<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * إصلاح بيانات: مبالغُ الدفعات التي افترقت عن سنداتها.
 *
 * ## ما حدث
 *
 * `commission_payouts.total` و`end_of_service_entries.amount` نسختان من مبلغ
 * سند الصرف تُكتبان لحظة الإنشاء. وتعديلُ السند بعدها — عكسٌ ثم قيدٌ مُصحّح، وهو
 * المسار المحاسبيّ السليم — كان يُصحّح الدفتر ويترك النسخة قديمة. فيقول الدفتر
 * ٨٬٣٧٧ ويقول أرشيف الدفعات ٧٬٣٣٧، ويُحسب الرصيد المتبقّي على القديم.
 *
 * القراءة صارت تُشتقّ من السند والمزامنة صارت تلقائية، لكنّ الصفوف التي عُدّلت
 * قبل ذلك تبقى كاذبةً في قاعدة البيانات — يقرؤها تصديرٌ أو استعلامٌ مباشر. فهذه
 * تُصلحها مرّةً واحدة.
 *
 * ولا `down`: إعادةُ رقمٍ خاطئ ليست تراجعًا.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('commission_payouts')
            ->whereNotNull('financial_voucher_id')
            ->orderBy('id')
            ->chunkById(200, function ($payouts) {
                foreach ($payouts as $payout) {
                    $voucher = DB::table('financial_vouchers')
                        ->where('id', $payout->financial_voucher_id)
                        ->first(['amount', 'treasury_id']);

                    if (! $voucher || (float) $voucher->amount === (float) $payout->total) {
                        continue;
                    }

                    DB::table('commission_payouts')->where('id', $payout->id)->update([
                        'total' => $voucher->amount,
                        'treasury_id' => $voucher->treasury_id ?? $payout->treasury_id,
                        'updated_at' => now(),
                    ]);
                }
            });

        DB::table('end_of_service_entries')
            ->whereNotNull('financial_voucher_id')
            ->orderBy('id')
            ->chunkById(200, function ($entries) {
                foreach ($entries as $entry) {
                    $voucher = DB::table('financial_vouchers')
                        ->where('id', $entry->financial_voucher_id)
                        ->first(['amount']);

                    // التصفية سالبة: الرصيد مجموعُ الحركات.
                    if (! $voucher || -(float) $voucher->amount === (float) $entry->amount) {
                        continue;
                    }

                    DB::table('end_of_service_entries')->where('id', $entry->id)->update([
                        'amount' => -(float) $voucher->amount,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // لا تراجع: الرقم الصحيح لا يُعاد إلى خطئه.
    }
};
