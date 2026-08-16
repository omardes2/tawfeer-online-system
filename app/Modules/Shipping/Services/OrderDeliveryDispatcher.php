<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\GeoProviderMapping;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * منسّق إرسال الطلب لشركة التوصيل عند التأكيد (المبدأ 13، ADR-038).
 * يبني الحمولة الموحّدة من بيانات الطلب ثم يفوّض الـDriver (Opost) لإنشاء الشحنة،
 * ويخزّن رقم التتبّع + يُنشئ سجلّ شحنة محليًا. لا يعرف الطلب بوجود Opost.
 *
 * التصميم دفاعي: فشل التكامل لا يكسر تأكيد الطلب — يُعاد ['status' => 'failed'] ويُسجَّل.
 */
class OrderDeliveryDispatcher
{
    public function __construct(private readonly DeliveryProviderManager $manager) {}

    /**
     * @return array{status: string, tracking_number?: ?string, message?: ?string}
     */
    public function dispatch(Order $order): array
    {
        $code = (string) config('shipping.provider', 'null');

        // لا مزوّد مُفعّل (بيئة بلا تكامل) ⇒ تخطٍّ صامت — التأكيد وحده كافٍ.
        if ($code === 'null' || $code === '') {
            return ['status' => 'skipped'];
        }

        // مُرسَل مسبقًا (حارس idempotency) ⇒ لا تكرار.
        if (! empty($order->tracking_number)) {
            return ['status' => 'skipped'];
        }

        // قفل ذرّي لكل طلب: يمنع الإرسال المزدوج عند التزامن (تأكيد فوري + مكنسة + زر يدوي).
        $lock = Cache::lock('ship-dispatch-'.$order->id, 30);
        if (! $lock->get()) {
            return ['status' => 'skipped']; // إرسال آخر لنفس الطلب جارٍ الآن.
        }

        try {
            // إعادة تحميل داخل القفل: قد يكون طلب متزامن آخر أضاف رقم التتبّع للتوّ.
            $order->refresh();
            if (! empty($order->tracking_number)) {
                return ['status' => 'skipped'];
            }

            $driver = $this->manager->driver($code);
            $provider = DeliveryProvider::where('code', $code)->first();

            $result = $driver->createShipment($this->buildPayload($order, $provider?->id));

            $tracking = $result['tracking_number'] ?? $result['reference'] ?? null;
            $externalId = $result['external_id'] ?? null;

            if (($result['status'] ?? null) !== 'created' || ($tracking === null && $externalId === null)) {
                Log::warning('Opost createShipment did not return a shipment', ['order' => $order->id, 'result' => $result]);
                $message = $result['message'] ?? __('لم تُرجِع شركة التوصيل رقم تتبّع.');
                $this->recordFailure($order, $message);

                return ['status' => 'failed', 'message' => $message];
            }

            $this->persist($order, $provider?->id, $tracking, $externalId, $result);

            return ['status' => 'created', 'tracking_number' => $tracking];
        } catch (\Throwable $e) {
            Log::warning('Order delivery dispatch failed: '.$e->getMessage(), ['order' => $order->id]);
            $this->recordFailure($order, $e->getMessage());

            return ['status' => 'failed', 'message' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * حمولة موحّدة يستهلكها الـDriver ويحوّلها لصيغة المزوّد. المعرّفات الخارجية (المدينة/المنطقة)
     * تُحلّ من تعيينات المزوّد (geo_provider_mappings) — لا تُرسَل أسماء (المتطلّب 9).
     *
     * @return array<string, mixed>
     */
    private function buildPayload(Order $order, ?int $providerId): array
    {
        // `attributeValues`: خيارات المتغيّر (لون/مقاس) تدخل وصف الأصناف.
        $order->loadMissing('items.variant.product', 'items.variant.attributeValues', 'creator.deliveryBusiness');

        // البزنس الذي تُدخَل الشحنة تحته = بزنس مُنشئ الطلب (إن رُبط)، وإلا الافتراضي من الإعدادات.
        $business = $order->creator?->deliveryBusiness;

        // بقرار صريح من مالك النظام: الكمية المُرسَلة هي **عدد الطرود** لا عدد
        // القطع. مجموع القطع كان يقول للشركة «عندي 20 طردًا» لطلبٍ يُسلَّم في
        // كيسٍ واحد، فتَرفضه (سقفها 12) ويدور في حلقة محاولات لا تنجح.
        // القِطع تبقى مفصّلةً في `items_description` كما هي.
        $qty = (int) max(1, (int) $order->parcels_count);
        $description = $order->items
            // بطلب صريح من مالك النظام: الخيارات ثم الكمية، كلٌّ بين نجمتين —
            // «شواية متنقلة *2*»، و«قميص قطني *أحمر - L* *2*» لصنفٍ بخيارات.
            // الخيارات تُذكر لأن مَن يجهّز الطرد ومَن يسلّمه لا يميّزان مقاسًا
            // من مقاس باسم المنتج وحده.
            ->map(function ($i) {
                $options = $i->optionsLabel();

                return trim(
                    ($i->variant?->product?->name ?? '')
                    .($options !== '' ? ' *'.$options.'*' : '')
                    .' *'.rtrim(rtrim((string) $i->qty, '0'), '.').'*'
                );
            })
            ->filter()->implode(' , ');

        // الدفع عند الاستلام هو النمط الافتراضي (يُلغى إن كان الطلب مدفوعًا مسبقًا).
        $isCod = $order->payment_status !== 'paid';

        return [
            'consignee_name' => $order->customer_name,
            'consignee_phone' => $order->customer_phone,
            'city_external_id' => $providerId ? $this->externalId(City::class, (int) $order->city_id, $providerId) : null,
            'area_external_id' => $providerId ? $this->externalId(Area::class, (int) $order->area_id, $providerId) : null,
            'address' => $order->shipping_address,
            'quantity' => $qty,
            'items_description' => $description !== '' ? $description : $order->number,
            'is_cod' => $isCod,
            'cod_amount' => $isCod ? (float) $order->total : 0,
            'has_return' => (bool) $order->has_return,
            'return_notes' => $order->return_notes,
            'notes' => $order->notes,
            'reference' => $order->number,
            // بزنس شركة التوصيل (اختياري): يتجاوز الافتراضي في الإعدادات عند ربط المستخدم.
            'business_external_id' => $business?->external_id,
            'business_address_external_id' => $business?->address_external_id,
        ];
    }

    /**
     * إلغاء شحنة الطلب لدى المزوّد (Opost) عند إلغاء الطلب. يستخدم المعرّف الخارجي المخزّن.
     *
     * @return array{status: string, message?: ?string}
     */
    public function cancelShipment(Order $order): array
    {
        $code = (string) config('shipping.provider', 'null');
        $reference = $order->delivery_external_id ?: $order->tracking_number;

        if ($code === 'null' || $code === '' || empty($reference)) {
            return ['status' => 'skipped'];
        }

        try {
            $ok = $this->manager->driver($code)->cancel((string) $reference);

            if ($ok) {
                // تحديث لقطة الشحنة المحلية (خارج آلة الحالات — مجرّد انعكاس).
                // provider_status ضمنها: هو المعروض في عمود «حالة أوبتيموس»، وبدونه يبقى
                // الطلب ظاهرًا «بانتظار الاستلام» رغم إلغاء طرده فعلًا لديهم.
                $order->shipments()->whereNotNull('external_id')->update([
                    'status' => 'cancelled',
                    'delivery_status' => 'cancelled',
                    'provider_status' => 'cancelled',
                ]);
                $order->update(['delivery_status' => 'cancelled']);

                return ['status' => 'cancelled'];
            }

            return ['status' => 'failed', 'message' => __('رفض المزوّد الإلغاء.')];
        } catch (\Throwable $e) {
            Log::warning('Order delivery cancel failed: '.$e->getMessage(), ['order' => $order->id]);

            return ['status' => 'failed', 'message' => $e->getMessage()];
        }
    }

    private function externalId(string $type, ?int $localId, int $providerId): ?string
    {
        if (! $localId) {
            return null;
        }

        return GeoProviderMapping::where('delivery_provider_id', $providerId)
            ->where('mappable_type', $type)
            ->where('mappable_id', $localId)
            ->value('external_id');
    }

    /** تخزين لقطة التتبّع على الطلب وإنشاء سجلّ شحنة محلي مطابق. */
    /**
     * تسجيل سبب فشل الإرسال على الطلب ليظهر في الواجهة بدل بقائه في سجلّ الأخطاء وحده
     * (الطلب كان يظهر «بانتظار التتبّع» دون تفسير حتى تنجح محاولة لاحقة).
     */
    private function recordFailure(Order $order, string $message): void
    {
        $order->forceFill([
            'delivery_dispatch_error' => mb_substr($message, 0, 500),
            'delivery_dispatch_attempts' => (int) $order->delivery_dispatch_attempts + 1,
            'delivery_dispatch_attempted_at' => now(),
        ])->saveQuietly();
    }

    private function persist(Order $order, ?int $providerId, ?string $tracking, ?string $externalId, array $result): void
    {
        DB::transaction(function () use ($order, $providerId, $tracking, $externalId, $result) {
            $order->update([
                'tracking_number' => $tracking,
                'delivery_external_id' => $externalId,
                'delivery_status' => $result['provider_status'] ?? 'submitted',
                'delivery_dispatch_error' => null, // نجح ⇒ يُمسح سبب الفشل السابق.
                'delivery_dispatch_attempted_at' => now(),
            ]);

            Shipment::create([
                'number' => NumberGenerator::next('shipments', 'number', 'SHP', (int) now()->year),
                'order_id' => $order->id,
                'branch_id' => $order->branch_id,
                'warehouse_id' => $order->warehouse_id,
                'status' => 'not_shipped',
                'carrier_name' => 'Opost',
                'tracking_number' => $tracking,
                'recipient_name' => $order->customer_name,
                'recipient_phone' => $order->customer_phone,
                'address_text' => $order->shipping_address,
                'city_id' => $order->city_id,
                'area_id' => $order->area_id,
                'shipping_cost' => (float) $order->shipping_total,
                'cost_source' => 'provider_live',
                'cost_currency' => 'ILS',
                'delivery_provider_id' => $providerId,
                'external_id' => $externalId,
                'provider_metadata' => $result['raw'] ?? null,
                'last_synced_at' => now(),
                'sync_status' => 'synced',
                'created_by' => auth()->id(),
            ]);
        });
    }
}
