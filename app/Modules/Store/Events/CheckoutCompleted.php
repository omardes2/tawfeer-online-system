<?php

namespace App\Modules\Store\Events;

use App\Modules\Sales\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * حدث دومين مستقرّ (ADR-032): اكتمل إتمام الشراء وأُنشئ الطلب (محجوز + دفعة مبدوءة).
 * يُطلَق بعد نجاح المعاملة (يُنشر خارج معاملة الخدمة — ADR-018). نقطة امتداد لسياق
 * النمو (تحويل/ولاء/تحليلات) — لا مستمع الآن، لا كلفة.
 */
class CheckoutCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly Order $order,
    ) {}
}
