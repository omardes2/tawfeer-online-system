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

    /**
     * تعيين حالة المزوّد الخام إلى الحالة القانونية الداخلية (ADR-038).
     * هنا **فقط** يعيش منطق المزوّد الخاص بالحالات — لا يُسرّب لوحدات الأعمال.
     * يُرجِع مفتاحًا من DeliveryStatus، أو null إن كانت حالة المزوّد غير معروفة.
     */
    public function mapProviderStatus(string $providerStatus): ?string;

    public function name(): string;
}
