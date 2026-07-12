<?php

namespace App\Modules\Shipping\Events;

use App\Modules\Shipping\Models\Shipment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين (ADR-038): تغيّرت الحالة القانونية للتوصيل. نقطة امتداد (إشعارات/تحليلات)
 * دون تسريب منطق مزوّد. لا يُنفَّذ أي منطق نمو/تسويق هنا.
 */
class DeliveryStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly Shipment $shipment,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly string $actorType,
        public readonly ?string $reasonCode = null,
    ) {}
}
