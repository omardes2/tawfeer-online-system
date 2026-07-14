<?php

namespace App\Support\Integrations\Shipping;

use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
        // أسماء Opost الفعلية (submitted/cancelled/…) + المختصرة (submit/cancel/…) كمرادفات.
        'submit' => DeliveryStatus::READY_FOR_PICKUP,
        'submitted' => DeliveryStatus::READY_FOR_PICKUP,
        'cancel' => DeliveryStatus::CANCELLED,
        'cancelled' => DeliveryStatus::CANCELLED,
        'pickup' => DeliveryStatus::PICKED_UP,
        'picked_up' => DeliveryStatus::PICKED_UP,
        'pending' => DeliveryStatus::ON_HOLD,
        'cod_pickup' => DeliveryStatus::DELIVERED_COD_HELD,
        'in_accounting' => DeliveryStatus::FUNDS_AT_ACCOUNTING,
        'return' => DeliveryStatus::RETURNING_TO_COURIER,
        'returned' => DeliveryStatus::RETURN_IN_TRANSIT,
        'delivered' => DeliveryStatus::RETURN_IN_TRANSIT,
        'close' => DeliveryStatus::CLOSED,
        'closed' => DeliveryStatus::CLOSED,
    ];

    private function client(?string $token): PendingRequest
    {
        return Http::withToken((string) $token)
            ->acceptJson()
            ->timeout(30)
            ->baseUrl(rtrim((string) config('services.opost.base_url', 'https://opost.ps/api'), '/'));
    }

    /**
     * تنفيذ طلب مع تجديد تلقائي للتوكن عند 401 (OAuth2 قصير الصلاحية).
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $auth = app(OpostTokenProvider::class);
        $res = $call($this->client($auth->token()));
        if ($res->status() === 401) {
            $res = $call($this->client($auth->token(true)));
        }

        return $res;
    }

    /**
     * إنشاء شحنة لدى Opost (POST /api/resources/shipments). يحوّل الحمولة الموحّدة
     * (من OrderDeliveryDispatcher) إلى صيغة Opost (consignee[...] + shipment_types[0][id] ...).
     * يُرجع شكلًا موحّدًا: status=created + tracking_number/external_id، أو status=failed برسالة.
     */
    public function createShipment(array $payload): array
    {
        $auth = app(OpostTokenProvider::class);
        if ($auth->token() === null) {
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

        $res = $this->send(fn (PendingRequest $c) => $c->asForm()->post('/resources/shipments', $body));

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
            // Opost يتتبّع بالمعرّف عبر ?id= ويعيد [ { data: [ {shipment} ] } ]، والحالة في last_status.
            $res = $this->send(fn (PendingRequest $c) => $c->get('/resources/shipments', ['id' => $trackingNumber]));
            if (! $res->successful()) {
                return ['provider_status' => null, 'external_id' => $trackingNumber, 'driver' => $this->name()];
            }
            $shipment = $this->firstShipment($res->json() ?? []);

            return [
                'provider_status' => $shipment['last_status']['status'] ?? $shipment['status'] ?? null,
                'external_id' => isset($shipment['id']) ? (string) $shipment['id'] : $trackingNumber,
                'raw' => $shipment,
                'driver' => $this->name(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Opost track error: '.$e->getMessage());

            return ['provider_status' => null, 'external_id' => $trackingNumber, 'driver' => $this->name()];
        }
    }

    /** فكّ غلاف Opost لأول شحنة: [ { data: [ {shipment}, ... ] } ] ⇒ {shipment}. */
    private function firstShipment(array $json): array
    {
        if (isset($json[0]['data'][0]) && is_array($json[0]['data'][0])) {
            return $json[0]['data'][0];
        }
        if (isset($json['data'][0]) && is_array($json['data'][0])) {
            return $json['data'][0];
        }
        if (isset($json['data']) && is_array($json['data'])) {
            return $json['data'];
        }

        return $json;
    }

    public function cancel(string $reference): bool
    {
        try {
            // Opost يلغي عبر إجراء جماعي: POST /resources/shipments/actions/cancel بـ selected_ids[].
            $res = $this->send(fn (PendingRequest $c) => $c->asForm()->post('/resources/shipments/actions/cancel', [
                'selected_ids' => [$reference],
                'notes' => __('أُلغي من النظام'),
            ]));

            if (! $res->successful()) {
                Log::warning('Opost cancel failed', ['status' => $res->status(), 'body' => $res->body(), 'ref' => $reference]);
            }

            return $res->successful();
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
            'external_id' => $payload['tracking_number'] ?? $payload['external_id'] ?? $payload['id'] ?? null,
            'provider_status' => $payload['status'] ?? $payload['last_status']['status'] ?? $payload['state'] ?? null,
        ];
    }

    public function name(): string
    {
        return 'opost';
    }
}
