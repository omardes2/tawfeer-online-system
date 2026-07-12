<?php

namespace App\Support\Integrations\Shipping;

use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;

/**
 * Driver مزوّد التوصيل Opost (ADR-038، المبدأ 13). كل منطق Opost الخاص محصور هنا:
 * خصوصًا **تعيين حالات Opost الخام إلى المفردات القانونية الداخلية**. لا تعرف وحدات
 * الأعمال بوجود Opost — تبديل/إضافة مزوّد = Driver جديد + إعداد، دون لمس منطق الأعمال.
 *
 * تكامل الـAPI الحيّ (createShipment/track/cancel) عبر بيانات اعتماد في .env يُفصَّل عند
 * ربط الحساب؛ هذه الدفعة تُرسي محرّك الحالات وطبقة التعيين.
 */
class OpostDeliveryProvider implements DeliveryProviderInterface
{
    /**
     * تعيين حالة Opost الخام ← الحالة القانونية الداخلية.
     * انتبه: أسماء Opost مضلّلة (`cod_pickup` = سُلّم للعميل، `delivered` = مرتجع عائد إلينا).
     *
     * @var array<string, string>
     */
    private const STATUS_MAP = [
        'draft' => DeliveryStatus::DRAFT,
        'submit' => DeliveryStatus::READY_FOR_PICKUP,
        'cancel' => DeliveryStatus::CANCELLED,
        'pickup' => DeliveryStatus::PICKED_UP,
        'pending' => DeliveryStatus::ON_HOLD,
        'cod_pickup' => DeliveryStatus::DELIVERED_COD_HELD,
        'in_accounting' => DeliveryStatus::FUNDS_AT_ACCOUNTING,
        'return' => DeliveryStatus::RETURNING_TO_COURIER,
        'delivered' => DeliveryStatus::RETURN_IN_TRANSIT,
        'close' => DeliveryStatus::CLOSED,
    ];

    public function createShipment(array $payload): array
    {
        // يُنفَّذ عند ربط الـAPI الحيّ (بيانات الاعتماد في .env).
        return ['status' => 'pending_integration', 'reference' => null, 'driver' => $this->name()];
    }

    public function track(string $trackingNumber): array
    {
        return ['status' => 'pending_integration', 'driver' => $this->name()];
    }

    public function cancel(string $reference): bool
    {
        return false;
    }

    public function mapProviderStatus(string $providerStatus): ?string
    {
        return self::STATUS_MAP[strtolower(trim($providerStatus))] ?? null;
    }

    public function name(): string
    {
        return 'opost';
    }
}
