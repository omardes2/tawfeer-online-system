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

    /**
     * هل يدعم المزوّد التحقّق من توقيع الـwebhook؟ (ADR-039)
     */
    public function supportsWebhookSignature(): bool;

    /**
     * التحقّق من توقيع حمولة webhook الخام (ADR-039). منطق التوقيع في الـDriver.
     *
     * @param  array<string, string>  $headers
     */
    public function verifyWebhookSignature(string $rawPayload, array $headers, ?string $secret): bool;

    /**
     * تطبيع حمولة webhook/تتبّع إلى مفاتيح موحّدة (ADR-039).
     *
     * @param  array<string, mixed>  $payload
     * @return array{event_id: ?string, external_id: ?string, provider_status: ?string}
     */
    public function parseWebhookEvent(array $payload): array;

    public function name(): string;
}
