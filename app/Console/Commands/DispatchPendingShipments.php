<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Illuminate\Console\Command;

/**
 * المكنسة الضامنة لوصول الطلبات لشركة التوصيل (الترحيل الجذري).
 *
 * الإرسال الأساسي متزامن لحظة تأكيد الطلب، لكن قد يتعذّر لحظيًا (Opost متوقّف، شبكة).
 * هذا الأمر يعمل كل دقيقة عبر المجدول ويلتقط أي طلب توصيل مؤكّد بلا رقم تتبّع فيعيد
 * إرساله — فيبقى النظام يحاول حتى ينجح دون اعتماد على عامل طابور دائم. idempotent
 * تمامًا (حارس + قفل داخل الـDispatcher يمنعان أي تكرار للشحنات).
 */
class DispatchPendingShipments extends Command
{
    protected $signature = 'shipping:dispatch-pending {--limit=100 : الحد الأقصى للطلبات في التمريرة الواحدة}';

    protected $description = 'إعادة إرسال طلبات التوصيل المؤكّدة التي لم يظهر لها رقم تتبّع بعد (ضمان الوصول لشركة التوصيل)';

    /** حالات الطلب التي يُتوقّع لها شحنة لدى شركة التوصيل (بعد التأكيد وقبل الإنهاء/الإلغاء). */
    private const DELIVERABLE_STATUSES = [
        'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship',
        'shipped', 'out_for_delivery', 'delayed', 'customer_unavailable',
    ];

    public function handle(OrderDeliveryDispatcher $dispatcher): int
    {
        // لا مزوّد مُفعّل ⇒ لا شيء لإرساله (بيئة بلا تكامل).
        if (config('shipping.provider', 'null') === 'null') {
            return self::SUCCESS;
        }

        $orders = Order::query()
            ->whereNull('tracking_number')
            ->whereNotNull('city_id')
            ->where('channel', '!=', 'pos')
            ->whereIn('status', self::DELIVERABLE_STATUSES)
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        $created = 0;
        $failed = 0;

        foreach ($orders as $order) {
            $status = $dispatcher->dispatch($order)['status'] ?? null;

            match ($status) {
                'created' => $created++,
                'failed' => $failed++,
                default => null, // skipped (مُرسَل أثناء التمريرة أو قفل نشط) — لا يُحتسب.
            };
        }

        $this->info(sprintf('shipping dispatch-pending: candidates=%d created=%d failed=%d', $orders->count(), $created, $failed));

        return self::SUCCESS;
    }
}
