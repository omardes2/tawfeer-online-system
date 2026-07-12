<?php

namespace App\Support\Integrations\Payment;

use App\Modules\Foundation\Models\PaymentMethod;
use App\Support\Contracts\Payment\PaymentProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * مصنع مزوّدي الدفع (ADR-028، المبدأ 13). النقطة الوحيدة لإنشاء مزوّد.
 * يربط طريقة الدفع (driver) بصنف Driver من config/payments.php، ويحقنه عبر الـ Container.
 * إضافة مزوّد جديد = صفّ طريقة + إدخال في الإعداد، دون لمس منطق الطلب/الدفع (المتطلّب 3).
 */
class PaymentProviderManager
{
    public function __construct(private readonly Container $app) {}

    public function for(PaymentMethod $method): PaymentProviderInterface
    {
        $driver = $method->driver ?: 'null';
        $class = config("payments.drivers.{$driver}", config('payments.drivers.null'));

        return $this->app->make($class);
    }
}
