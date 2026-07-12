<?php

namespace App\Support\Contracts\Shipping;

/**
 * عقد مزوّد التوصيل (المبدأ 13، ADR-027). أي شركة توصيل خلف هذا العقد + Driver.
 * لا يُستدعى مزوّد مباشرةً من متحكم/نموذج — فقط عبر هذه الطبقة.
 */
interface DeliveryProviderInterface
{
    /**
     * إنشاء شحنة لدى المزوّد وإرجاع مرجعها (tracking/label/external_id...).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createShipment(array $payload): array;

    /**
     * تتبّع شحنة عبر رقم التتبّع.
     *
     * @return array<string, mixed>
     */
    public function track(string $trackingNumber): array;

    /**
     * إلغاء شحنة لدى المزوّد.
     */
    public function cancel(string $reference): bool;

    public function name(): string;
}
