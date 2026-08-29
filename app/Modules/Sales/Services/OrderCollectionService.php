<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسجيل ما حُصِّل فعلًا من الزبون، وإقفال الفرق.
 *
 * ## المشكلة
 *
 * شركة التوصيل تُعدّل مبلغ التحصيل قبل التسليم: طلبٌ إجماليّه ٦٤٠ يصل ملصقُه
 * بـ`COD: 620`. والنظام يُعلّم الطلب «مدفوعًا بالكامل» عند التسليم لأنه لا يعرف
 * إلّا رقمًا واحدًا — فتقول الفاتورة ٦٤٠ مدفوعة، والصندوق يستلم ٦٢٠، ولا شيء
 * يقول أين ذهب الفرق.
 *
 * ## أين يقع الفرق محاسبيًّا
 *
 * ترحيلُ البيع يقع على **قيمة البضاعة بلا رسوم التوصيل** (`bookableTotal`).
 * فالفرق نوعان:
 *
 * ```
 * إجمالي ٦٤٠ = بضاعة ٦٢٠ + توصيل ٢٠     ومُحصَّل ٦٢٠
 *   └── البضاعة أُقفلت كاملةً ⇒ لا قيد. الفرق خرج من هامش التوصيل.
 *
 * إجمالي ٦٤٠ = بضاعة ٦٢٠ + توصيل ٢٠     ومُحصَّل ٥٩٠
 *   └── نقصٌ على البضاعة ٣٠ ⇒ قيد خصم: مدين ٥٠٣٠ / دائن ذمّة الطلب.
 * ```
 *
 * فالقيد يقع على **ما يمسّ البضاعة وحده**. وتقييدُ كامل الفرق خصمًا يُنقص
 * الإيراد بمبلغِ توصيلٍ لم يكن إيرادًا أصلًا.
 *
 * ## وإعادة التسجيل تُصحّح لا تُراكم
 *
 * الرقم يُدخَل بيدٍ من ملصقٍ أو كشف، فيُخطئ ويُعاد. فقيدُ الفرق السابق يُعكس
 * قبل كتابة الجديد — وإلّا تراكمت خصومٌ على طلبٍ واحد.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * لا يمسّ هذا شيئًا من مسار التوصيل: لا حمولةَ إرسالٍ ولا webhook ولا حالات.
 * الرقم يُدخَل يدويًّا بعد الواقعة، والقيدُ محاسبيّ بحت.
 */
class OrderCollectionService
{
    /** حساب الخصومات — مقابلٌ للإيراد يحمل ما لم يُحصَّل من قيمة البضاعة. */
    public const DISCOUNT_ACCOUNT = '5030';

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly SalesPostingService $posting,
    ) {}

    /**
     * تسجيل المُحصَّل الفعليّ وإقفال الطلب عليه.
     *
     * @param  float  $collected  ما دفعه الزبون فعلًا (من ملصق شركة التوصيل أو كشفها)
     */
    public function record(Order $order, float $collected, ?string $note, User $actor): Order
    {
        $collected = round($collected, 2);
        $total = round((float) $order->total, 2);

        if ($order->status === 'cancelled') {
            throw ValidationException::withMessages(['order' => __('لا يُسجَّل تحصيل على طلب ملغى.')]);
        }

        if ($collected < 0) {
            throw ValidationException::withMessages(['collected_total' => __('المبلغ لا يكون سالبًا.')]);
        }

        // الزيادة عن الإجمالي ليست تحصيلًا بل خطأ إدخال: المندوب لا يقبض أكثر
        // ممّا على الملصق، ولو قبض فهي واقعةٌ أخرى تُسجَّل سندَ قبضٍ مستقلّ.
        if ($collected > $total + 0.001) {
            throw ValidationException::withMessages([
                'collected_total' => __('المُحصَّل يتجاوز إجمالي الطلب (:t).', ['t' => number_format($total, 2)]),
            ]);
        }

        return DB::transaction(function () use ($order, $collected, $note, $actor, $total) {
            // إعادة التسجيل تُصحّح: يُعكس قيدُ الفرق السابق قبل كتابة الجديد.
            $this->reverseExistingEntry($order, $actor);

            $entry = $this->postGoodsShortfall($order, $collected);

            $order->update([
                'collected_total' => $collected,
                'collection_note' => $note,
                'collection_recorded_at' => now(),
                'collection_recorded_by' => $actor->id,
                'collection_entry_id' => $entry?->id,
                // الطلب يُقفل على ما حُصِّل: الفرق خصمٌ مقبول لا دَينٌ مفتوح.
                'amount_paid' => $collected,
                'payment_status' => $collected + 0.001 >= $total ? 'paid' : 'partially_paid',
            ]);

            return $order->fresh();
        });
    }

    /** إلغاء التسجيل والعودة إلى ما كان — يُعكس القيد ويُفرَّغ الحقول. */
    public function clear(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $this->reverseExistingEntry($order, $actor);

            $order->update([
                'collected_total' => null,
                'collection_note' => null,
                'collection_recorded_at' => null,
                'collection_recorded_by' => null,
                'collection_entry_id' => null,
            ]);

            return $order->fresh();
        });
    }

    // ————————————————————————— الحساب —————————————————————————

    /**
     * ما نقص من **قيمة البضاعة** — وهو وحده ما يستحقّ قيدًا.
     *
     * الفرق الذي لا يتجاوز رسوم التوصيل خرج من هامشها لا من الإيراد.
     */
    public function goodsShortfall(Order $order, float $collected): float
    {
        $bookable = $this->posting->bookableTotal($order);

        return round(max(0, $bookable - $collected), 2);
    }

    /** فرقُ التحصيل كاملًا — للعرض والفلترة. */
    public function variance(Order $order): float
    {
        if ($order->collected_total === null) {
            return 0.0;
        }

        return round((float) $order->total - (float) $order->collected_total, 2);
    }

    // ————————————————————————— القيد —————————————————————————

    private function postGoodsShortfall(Order $order, float $collected): ?JournalEntry
    {
        $shortfall = $this->goodsShortfall($order, $collected);

        if ($shortfall <= 0) {
            return null;
        }

        $receivable = $this->posting->receivableAccountCode($order);

        if (! $receivable) {
            throw ValidationException::withMessages(['order' => __('لا يوجد حساب مديونية صالح للطلب.')]);
        }

        return $this->accounting->postEntry([
            'entry_date' => now()->toDateString(),
            'description' => __('فرق تحصيل الطلب :n', ['n' => $order->number]),
            'source' => 'order_collection',
            'reference_type' => 'order_collection',
            'reference_id' => $order->id,
        ], [
            ['account_code' => self::DISCOUNT_ACCOUNT, 'debit' => $shortfall, 'credit' => 0],
            ['account_code' => $receivable, 'debit' => 0, 'credit' => $shortfall],
        ]);
    }

    private function reverseExistingEntry(Order $order, User $actor): void
    {
        if (! $order->collection_entry_id) {
            return;
        }

        $entry = JournalEntry::find($order->collection_entry_id);

        if ($entry && ! $entry->isReversed()) {
            $this->accounting->reverse($entry, [
                'description' => __('عكس فرق تحصيل الطلب :n', ['n' => $order->number]),
            ]);
        }

        $order->forceFill(['collection_entry_id' => null])->save();
    }
}
