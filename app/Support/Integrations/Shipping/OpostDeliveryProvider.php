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
        'drafts' => DeliveryStatus::DRAFT,
        'darft' => DeliveryStatus::DRAFT,  // خطأ إملائي في واجهة Opost («Darfts») — تحصين.
        'darfts' => DeliveryStatus::DRAFT,
        // أسماء Opost الفعلية (submitted/cancelled/…) + المختصرة (submit/cancel/…) كمرادفات.
        'submit' => DeliveryStatus::READY_FOR_PICKUP,
        'submitted' => DeliveryStatus::READY_FOR_PICKUP,
        // «Pending Pickup»: سُلّمت للشركة وتنتظر تحميل المندوب — ما تزال قبل الاستلام.
        'pending_pickup' => DeliveryStatus::READY_FOR_PICKUP,
        'pending pickup' => DeliveryStatus::READY_FOR_PICKUP,
        'cancel' => DeliveryStatus::CANCELLED,
        'cancelled' => DeliveryStatus::CANCELLED,
        'pickup' => DeliveryStatus::PICKED_UP,
        'picked_up' => DeliveryStatus::PICKED_UP,
        'picked up' => DeliveryStatus::PICKED_UP,
        // «On Deliver»: الطرد مع المندوب في طريقه للعميل — محمول لدى الشركة.
        'on_deliver' => DeliveryStatus::PICKED_UP,
        'on deliver' => DeliveryStatus::PICKED_UP,
        'on_delivery' => DeliveryStatus::PICKED_UP,
        'out_for_delivery' => DeliveryStatus::PICKED_UP,
        'pending' => DeliveryStatus::ON_HOLD,
        'cod_pickup' => DeliveryStatus::DELIVERED_COD_HELD,
        'cod pickup' => DeliveryStatus::DELIVERED_COD_HELD,
        'in_accounting' => DeliveryStatus::FUNDS_AT_ACCOUNTING,
        'in accounting' => DeliveryStatus::FUNDS_AT_ACCOUNTING,
        // «return/returned» في Opost = الطرد راجع وموجود في المكتب (لم يصلنا بعد).
        'return' => DeliveryStatus::RETURNING_TO_COURIER,
        'returned' => DeliveryStatus::RETURNING_TO_COURIER,
        // «delivered» في Opost = الطرد الراجع دخل فاتورة إرجاع في طريقه إلينا (لا تسليم للعميل).
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
            // بزنس المستخدم المربوط (من الحمولة) يتجاوز الافتراضي في الإعدادات.
            'business' => $payload['business_external_id'] ?? config('services.opost.business_id'),
            'business_address' => $payload['business_address_external_id'] ?? config('services.opost.business_address_id'),
            'shipment_types[0][id]' => config('services.opost.shipment_type_id', 1),
            'consignee[name]' => $payload['consignee_name'] ?? null,
            'consignee[phone]' => $this->normalizePhone($payload['consignee_phone'] ?? null),
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

    /**
     * جلب حسابات «البزنس» من Opost (GET /resources/businesses افتراضيًا — قابل للضبط).
     * تحليل متسامح لأسماء الحقول (كما في مزامنة الجغرافيا). يعيد عناصر مُطبَّعة.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullBusinesses(): iterable
    {
        $auth = app(OpostTokenProvider::class);
        if ($auth->token() === null) {
            return [];
        }

        try {
            $path = (string) config('services.opost.businesses_path', '/resources/businesses');
            $res = $this->send(fn (PendingRequest $c) => $c->get($path, ['limit' => 200]));
            if (! $res->successful()) {
                Log::warning('Opost pullBusinesses failed', ['status' => $res->status(), 'body' => mb_substr($res->body(), 0, 500)]);

                return [];
            }

            return $this->normalizeBusinesses($res->json() ?? []);
        } catch (\Throwable $e) {
            Log::warning('Opost pullBusinesses error: '.$e->getMessage());

            return [];
        }
    }

    /**
     * استخراج وتطبيع سجلّات البزنس من ردّ Opost (يدعم مغلّف [{data:[...]}] والقوائم المباشرة).
     *
     * @param  array<mixed>  $json
     * @return array<int, array<string, mixed>>
     */
    private function normalizeBusinesses(array $json): array
    {
        // تجميع أجزاء data من شكل Opost [{data:[...]}]، وإلا استخدام القائمة/المغلّف مباشرة.
        $rows = [];
        if (array_is_list($json)) {
            $wrapped = false;
            foreach ($json as $part) {
                if (is_array($part) && isset($part['data']) && is_array($part['data'])) {
                    $rows = array_merge($rows, $part['data']);
                    $wrapped = true;
                }
            }
            if (! $wrapped) {
                $rows = $json;
            }
        } else {
            foreach (['data', 'businesses', 'results', 'items'] as $k) {
                if (isset($json[$k]) && is_array($json[$k])) {
                    $rows = $json[$k];
                    break;
                }
            }
            if ($rows === [] && isset($json['id'])) {
                $rows = [$json]; // كائن بزنس مفرد
            }
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $externalId = $row['id'] ?? $row['business_id'] ?? $row['uuid'] ?? null;
            if ($externalId === null || $externalId === '') {
                continue;
            }
            // عنوان الالتقاط الافتراضي: قد يأتي ككائن أو معرّف مباشر.
            $address = $row['default_address'] ?? $row['address'] ?? $row['business_address'] ?? null;
            $addressId = is_array($address) ? ($address['id'] ?? null) : $address;

            $out[] = [
                'external_id' => (string) $externalId,
                'name' => (string) ($row['name'] ?? $row['title'] ?? $row['business_name'] ?? ('#'.$externalId)),
                'address_external_id' => $addressId !== null && $addressId !== '' ? (string) $addressId : null,
                'phone' => isset($row['phone']) ? (string) $row['phone'] : (isset($row['mobile']) ? (string) $row['mobile'] : null),
                'raw' => $row,
            ];
        }

        return $out;
    }

    /**
     * تطبيع رقم الهاتف لصيغة Opost (10 خانات محلّية): إزالة الرموز، تحويل مقدّمة الدولة
     * الفلسطينية 970/00970 إلى صفر محلّي، وإضافة صفر بادئ لرقم من 9 خانات.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00970')) {
            $digits = '0'.substr($digits, 5);
        } elseif (str_starts_with($digits, '970')) {
            $digits = '0'.substr($digits, 3);
        }

        if (strlen($digits) === 9 && $digits[0] !== '0') {
            $digits = '0'.$digits;
        }

        return $digits !== '' ? $digits : null;
    }

    public function track(string $trackingNumber): array
    {
        try {
            // Opost يتتبّع بالمعرّف عبر ?id= ويعيد [ { data: [ {shipment} ] } ]، والحالة في last_status.
            $res = $this->send(fn (PendingRequest $c) => $c->get('/resources/shipments', ['id' => $trackingNumber]));
            if (! $res->successful()) {
                Log::warning('Opost track failed', ['status' => $res->status(), 'ref' => $trackingNumber]);

                // `error` صريح: بدونه يُفسَّر الفشل كـ«لا تغيير» فيُخفى العطل تمامًا.
                return [
                    'provider_status' => null,
                    'external_id' => $trackingNumber,
                    'error' => __('تعذّر الاستعلام عن الشحنة (HTTP :s).', ['s' => $res->status()]),
                    'driver' => $this->name(),
                ];
            }
            $shipment = $this->firstShipment($res->json() ?? []);

            // ردّ ناجح بلا شحنة مطابقة ⇒ المرجع غير معروف لدى المزوّد (لا «لا تغيير»).
            if ($shipment === []) {
                return [
                    'provider_status' => null,
                    'external_id' => $trackingNumber,
                    'error' => __('لم تُعثَر الشحنة لدى شركة التوصيل بهذا المرجع.'),
                    'driver' => $this->name(),
                ];
            }

            return [
                'provider_status' => $shipment['last_status']['status'] ?? $shipment['status'] ?? null,
                'external_id' => isset($shipment['id']) ? (string) $shipment['id'] : $trackingNumber,
                'raw' => $shipment,
                'driver' => $this->name(),
            ];
        } catch (\Throwable $e) {
            Log::warning('Opost track error: '.$e->getMessage());

            return [
                'provider_status' => null,
                'external_id' => $trackingNumber,
                'error' => $e->getMessage(),
                'driver' => $this->name(),
            ];
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

    /**
     * تطبيع حمولة webhook من Opost. الشكل الفعلي: كائن الشحنة كاملًا، ومعرّفها في
     * consignee.shipment_id (أو id العلوي)، والحالة في أحدث عنصر من status_history
     * (أو last_status.status). event_id = معرّف آخر تغيير حالة (فريد لكل حدث).
     */
    public function parseWebhookEvent(array $payload): array
    {
        $shipmentId = $payload['id']
            ?? $payload['shipment_id']
            ?? $payload['external_id']
            ?? $payload['tracking_number']
            ?? ($payload['consignee']['shipment_id'] ?? null);

        $status = $payload['last_status']['status'] ?? $payload['status'] ?? null;
        $eventId = $payload['last_status']['id'] ?? $payload['event_id'] ?? null;

        // الحالة الحديثة من سجلّ الحالات (Opost يضعها في status_history).
        if (! empty($payload['status_history']) && is_array($payload['status_history'])) {
            $latest = $this->latestHistory($payload['status_history']);
            $status ??= $latest['status'] ?? null;
            $eventId ??= $latest['id'] ?? null;
            $shipmentId ??= $latest['shipment_id'] ?? null;
        }

        return [
            'event_id' => $eventId !== null ? (string) $eventId : null,
            'external_id' => $shipmentId !== null ? (string) $shipmentId : null,
            'provider_status' => $status,
        ];
    }

    /** أحدث عنصر في status_history (بأكبر id). */
    /**
     * أحدث عنصر في سجلّ الحالات. الترتيب بأولوية: الطابع الزمني، ثم المعرّف الرقمي، ثم
     * ترتيب المصفوفة (آخر عنصر). الاعتماد على معرّف رقمي وحده كان يلتقط **أقدم** حالة
     * حين تُرسل معرّفات نصّية (UUID) فتُطبَّق حالة قديمة.
     *
     * @param  array<int, mixed>  $history
     * @return array<string, mixed>
     */
    private function latestHistory(array $history): array
    {
        $rows = array_values(array_filter($history, 'is_array'));
        if ($rows === []) {
            return [];
        }

        $rank = function (array $row): array {
            $time = null;
            foreach (['created_at', 'updated_at', 'date', 'timestamp'] as $key) {
                if (! empty($row[$key])) {
                    $ts = strtotime((string) $row[$key]);
                    if ($ts !== false) {
                        $time = $ts;
                        break;
                    }
                }
            }
            $id = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : null;

            // مفتاح مركّب: وجود الوقت أولًا، ثم الوقت، ثم وجود المعرّف الرقمي، ثم المعرّف.
            return [$time !== null ? 1 : 0, $time ?? 0, $id !== null ? 1 : 0, $id ?? 0];
        };

        $latest = $rows[0];
        $latestRank = $rank($latest);
        foreach (array_slice($rows, 1) as $row) {
            $r = $rank($row);
            // «أكبر أو يساوي» ⇒ عند تساوي الرتب يفوز الأخير (ترتيب المصفوفة = ترتيب زمني).
            if ($r >= $latestRank) {
                $latest = $row;
                $latestRank = $r;
            }
        }

        return $latest;
    }

    public function name(): string
    {
        return 'opost';
    }
}
