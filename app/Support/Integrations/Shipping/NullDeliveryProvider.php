<?php

namespace App\Support\Integrations\Shipping;

use App\Support\Contracts\Shipping\DeliveryProviderInterface;

/**
 * Driver افتراضي "لا مزوّد" للتوصيل — placeholder آمن (لا شبكة) حتى ربط شركة حقيقية.
 */
class NullDeliveryProvider implements DeliveryProviderInterface
{
    public function createShipment(array $payload): array
    {
        return ['status' => 'unavailable', 'reference' => null, 'driver' => $this->name()];
    }

    public function track(string $trackingNumber): array
    {
        return ['status' => 'unavailable', 'driver' => $this->name()];
    }

    public function cancel(string $reference): bool
    {
        return false;
    }

    public function mapProviderStatus(string $providerStatus): ?string
    {
        return null; // لا مزوّد ⇒ لا تعيين حالات.
    }

    public function name(): string
    {
        return 'null';
    }
}
