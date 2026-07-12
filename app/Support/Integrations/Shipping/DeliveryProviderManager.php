<?php

namespace App\Support\Integrations\Shipping;

use App\Modules\Shipping\Models\Shipment;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use Illuminate\Contracts\Container\Container;

/**
 * مُحلّل مزوّدي التوصيل (ADR-039، المبدأ 13). يحلّ الـDriver المناسب بحسب **كود المزوّد**
 * (من config/shipping.php)، فيدعم **عدّة مزوّدين** في آنٍ دون لمس منطق الأعمال:
 * الـwebhook يحلّ بالكود من المسار، والمزامنة/الاستيعاب بكود مزوّد الشحنة.
 */
class DeliveryProviderManager
{
    /** @var array<string, DeliveryProviderInterface> */
    private array $resolved = [];

    public function __construct(private readonly Container $app) {}

    /** الـDriver الافتراضي (config('shipping.provider')). */
    public function default(): DeliveryProviderInterface
    {
        return $this->driver(config('shipping.provider', 'null'));
    }

    /** حلّ Driver التوصيل بكود المزوّد؛ يعود للافتراضي إن كان الكود مجهولًا. */
    public function driver(?string $code): DeliveryProviderInterface
    {
        $code = $code ?: config('shipping.provider', 'null');

        if (isset($this->resolved[$code])) {
            return $this->resolved[$code];
        }

        $class = config("shipping.drivers.$code.delivery");
        if ($class === null) {
            // كود غير معرّف ⇒ العقد المربوط افتراضيًا (لا كسر).
            return $this->resolved[$code] = $this->app->make(DeliveryProviderInterface::class);
        }

        return $this->resolved[$code] = $this->app->make($class);
    }

    /** الـDriver الخاص بمزوّد الشحنة (أو الافتراضي إن لم يُعيَّن). */
    public function forShipment(Shipment $shipment): DeliveryProviderInterface
    {
        return $this->driver($shipment->provider?->driver);
    }
}
