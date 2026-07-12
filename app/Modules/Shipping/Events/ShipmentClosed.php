<?php

namespace App\Modules\Shipping\Events;

use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (ADR-038): أُغلقت الشحنة ماليًا (CLOSE) — نقطة اكتمال المال الوحيدة.
 * عندها يصبح الطلب مُسوًّى وتصبح العمولات مستحقّة (eligible). نقطة امتداد للترحيل
 * المحاسبي النهائي في 4.6 (لا محاسبة نهائية هنا).
 */
class ShipmentClosed
{
    use Dispatchable;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly Order $order,
    ) {}
}
