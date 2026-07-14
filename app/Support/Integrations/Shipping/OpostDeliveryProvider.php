<?php

namespace App\Support\Integrations\Shipping;

use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

    private function client(): PendingRequest
    {
        return Http::withToken((string) config('services.opost.token'))
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) config('services.opost.base_url', 'https://opost.ps/api'), '/'));
    }

    /**
     * إنشاء شحنة لدى Opost (POST /api/resources/shipments). يحوّل الحمولة الموحّدة
     * (من OrderDeliveryDispatcher) إلى صيغة Opost (consignee[...] + shipment_types[0][id] ...).
     * يُرجع شكلًا موحّدًا: status=created + tracking_number/external_id، أو status=failed برسالة.
     */
    public function createShipment(array $payload): array
    {
        $token = (string) config('services.opost.token');
        if ($token === '') {
            return ['status' => 'failed', 'message' => __('لم تُضبط بيانات اعتماد شركة التوصيل.'), 'driver' => $this->name()];
        }

        $body = array_filter([
            'business' => config('services.opost.business_id'),
            'business_address' => config('services.opost.business_address_id'),
            'shipment_types[0][id]' => config('services.opost.shipment_type_id', 1),
            'consignee[name]' => $payload['consignee_name'] ?? null,
            'consignee[phone]' => $payload['consignee_phone'] ?? null,
            'consignee[city]' => $payload['city_external_id'] ?? null,
            'consignee[area]' => $payload['area_external_id'] ?? null,
            'consignee[address]' => $payload['address'] ?? null,
            'quantity' => $payload['quantity'] ?? 1,
            'items_description' => $payload['items_description'] ?? null,
            'is_cod' => ! empty($payload['is_cod']) ? 1 : 0,
            'cod_amount' => $payload['cod_amount'] ?? 0,
            'has_return' => ! empty($payload['has_return']) ? 1 : 0,
            'return_notes' => $payload['return_notes'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $res = $this->client()->asForm()->post('/resources/shipments', $body);

        if (! $res->successful()) {
            Log::warning('Opost createShipment failed', ['status' => $res->status(), 'body' => $res->body()]);

            return ['status' => 'failed', 'message' => __('فشل إنشاء الشحنة (:s).', ['s' => $res->status()]), 'driver' => $this->name()];
        }

        $json = $res->json() ?? [];
        $data = $json['data'] ?? $json['shipment'] ?? $json;

        return [
            'status' => 'created',
            'tracking_number' => $data['barcode'] ?? $data['tracking_number'] ?? $data['tracking'] ?? ($data['id'] ?? null),
            'external_id' => isset($data['id']) ? (string) $data['id'] : null,
            'provider_status' => $data['status'] ?? null,
            'raw' => $data,
            'driver' => $this->name(),
        ];
    }

    public function track(string $trackingNumber): array
    {
        try {
            $res = $this->client()->get('/resources/shipments/'.rawurlencode($trackingNumber));
            if (! $res->successful()) {
                return ['provider_status' => null, 'external_id' => $trackingNumber, 'driver' => $this->name()];
            }
            $data = $res->json('data') ?? $res->json() ?? [];

            return [
                'provider_status' => $data['status'] ?? null,
                'external_id' => isset($data['id']) ? (string) $data['id'] : $trackingNumber,
                'raw' => $data,
                'driver' => $this->name(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Opost track error: '.$e->getMessage());

            return ['provider_status' => null, 'external_id' => $trackingNumber, 'driver' => $this->name()];
        }
    }

    public function cancel(string $reference): bool
    {
        try {
            return $this->client()->delete('/resources/shipments/'.rawurlencode($reference))->successful();
        } catch (\Throwable $e) {
            Log::warning('Opost cancel error: '.$e->getMessage());

            return false;
        }
    }

    public function mapProviderStatus(string $providerStatus): ?string
    {
        return self::STATUS_MAP[strtolower(trim($providerStatus))] ?? null;
    }

    public function supportsWebhookSignature(): bool
    {
        return true;
    }

    public function verifyWebhookSignature(string $rawPayload, array $headers, ?string $secret): bool
    {
        if ($secret === null || $secret === '') {
            return false; // بلا سرّ ⇒ لا يمكن التحقّق (يُعامَل كغير متحقّق أعلى).
        }
        // ترويسة التوقيع (غير حسّاسة لحالة الأحرف).
        $normalized = array_change_key_case($headers, CASE_LOWER);
        $provided = $normalized['x-opost-signature'] ?? '';
        $expected = hash_hmac('sha256', $rawPayload, $secret);

        return $provided !== '' && hash_equals($expected, $provided);
    }

    public function parseWebhookEvent(array $payload): array
    {
        return [
            'event_id' => $payload['event_id'] ?? $payload['id'] ?? null,
            'external_id' => $payload['tracking_number'] ?? $payload['external_id'] ?? null,
            'provider_status' => $payload['status'] ?? $payload['state'] ?? null,
        ];
    }

    public function name(): string
    {
        return 'opost';
    }
}
