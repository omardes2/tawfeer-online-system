<?php

namespace App\Console\Commands;

use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderVoidService;
use Illuminate\Console\Command;

/**
 * المكنسة الضامنة لإلغاء الطرود لدى شركة التوصيل.
 *
 * إلغاء الطلب يحاول إلغاء الشحنة لدى المزوّد فورًا، لكن قد يتعذّر لحظيًا (المزوّد متوقّف،
 * شبكة). بلا إعادة محاولة يبقى الطرد **نشطًا لديهم** فيصل العميل بضاعةً لطلبٍ ملغى —
 * خسارة فعلية. هذا الأمر يعمل كل دقيقة ويلتقط كل طلب ملغى/محذوف بقي عليه أثر فشل
 * إلغاء، فيعيد المحاولة حتى ينجح ويُمسح الأثر.
 *
 * يشمل المحذوفين ناعمًا: الحذف الإداري يُلغي الطلب عندنا، والطرد يجب أن يُلغى لديهم أيضًا.
 */
class CancelPendingShipments extends Command
{
    protected $signature = 'shipping:cancel-pending {--limit=100 : الحد الأقصى للطلبات في التمريرة الواحدة}';

    protected $description = 'إعادة محاولة إلغاء الشحنات لدى شركة التوصيل للطلبات الملغاة التي تعذّر إلغاؤها';

    public function handle(OrderVoidService $void): int
    {
        if (config('shipping.provider', 'null') === 'null') {
            return self::SUCCESS; // بيئة بلا تكامل.
        }

        $orders = Order::withTrashed()
            ->whereNotNull('delivery_cancel_error')
            ->orderBy('delivery_cancel_attempted_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $cancelled = 0;
        $failed = 0;

        foreach ($orders as $order) {
            match ($void->cancelAtProvider($order)['status'] ?? null) {
                'cancelled', 'skipped' => $cancelled++,
                'failed' => $failed++,
                default => null,
            };
        }

        $this->info(sprintf(
            'shipping cancel-pending: candidates=%d cancelled=%d still-failing=%d',
            $orders->count(), $cancelled, $failed,
        ));

        if ($failed > 0) {
            $this->warn('  ⚠ طرود ما زالت نشطة لدى شركة التوصيل رغم إلغاء طلباتها — راجع قائمة الطلبات.');
        }

        return self::SUCCESS;
    }
}
