<?php

namespace App\Support;

use App\Modules\Sales\Models\Order;
use Illuminate\Support\Facades\Schema;

/**
 * حالة تشغيلية مختصرة لمنظومة إرسال الطلبات لشركة التوصيل — لعرض مؤشّر في اللوحة.
 *
 * بعد اعتماد «الترحيل الجذري» (إرسال متزامن لحظة التأكيد + مكنسة مجدولة تضمن الوصول)
 * لم يعد الإرسال يعتمد على عامل طابور، فالمؤشّر يقيس ما يهمّ فعلًا: هل يوجد طلب توصيل
 * مؤكّد لم يصل لشركة التوصيل بعد (بلا رقم تتبّع) وتجاوز مهلة السماح؟ إن وُجد فهذا يعني
 * أن المجدول (cron) متوقّف — وإلا لالتقطته المكنسة خلال دقيقة.
 */
class SystemHealth
{
    /** مهلة سماح قبل اعتبار الطلب «عالقًا» (دقائق) — أكبر من دورة المكنسة (دقيقة). */
    private const STUCK_MINUTES = 3;

    /** حالات الطلب التي يُتوقّع لها شحنة لدى شركة التوصيل (بعد التأكيد وقبل الإنهاء/الإلغاء). */
    private const DELIVERABLE_STATUSES = [
        'confirmed', 'stock_reserved', 'preparing', 'ready_to_ship',
        'shipped', 'out_for_delivery', 'delayed', 'customer_unavailable',
    ];

    /** @return array<string, mixed> */
    public static function delivery(): array
    {
        $provider = (string) config('shipping.provider', 'null');
        $enabled = $provider !== 'null';

        $stuck = $enabled ? self::stuckDeliveryOrders() : 0;

        return [
            'provider' => $provider,
            'enabled' => $enabled,
            'stuck' => $stuck,   // طلبات توصيل مؤكّدة بلا رقم تتبّع تجاوزت مهلة السماح
            'ok' => $enabled && $stuck === 0,
        ];
    }

    /** عدد طلبات التوصيل المؤكّدة التي لم تصل لشركة التوصيل بعد وتجاوزت مهلة السماح. */
    private static function stuckDeliveryOrders(): int
    {
        if (! Schema::hasTable('orders')) {
            return 0;
        }

        $threshold = now()->subMinutes(self::STUCK_MINUTES);

        try {
            return (int) Order::query()
                ->whereNull('tracking_number')
                ->whereNotNull('city_id')
                ->where('channel', '!=', 'pos')
                ->whereIn('status', self::DELIVERABLE_STATUSES)
                ->where(function ($q) use ($threshold) {
                    // مؤكّد منذ مدّة (أو أُنشئ منذ مدّة إن غاب تاريخ التأكيد) — أعطينا المكنسة فرصة.
                    $q->where('confirmed_at', '<=', $threshold)
                        ->orWhere(function ($q2) use ($threshold) {
                            $q2->whereNull('confirmed_at')->where('created_at', '<=', $threshold);
                        });
                })
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
