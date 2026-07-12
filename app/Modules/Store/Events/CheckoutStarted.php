<?php

namespace App\Modules\Store\Events;

use App\Models\User;
use App\Modules\Store\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين مستقرّ (ADR-032): بدأ العميل إتمام الشراء لسلة نشطة.
 * نقطة امتداد لسياق النمو (هجر السلة/رحلة العميل) — لا مستمع الآن، لا كلفة.
 */
class CheckoutStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Cart $cart,
        public readonly ?User $user = null,
    ) {}
}
