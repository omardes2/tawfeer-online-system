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

        try {
            $driver = $this->manager->driver($code);
            $provider = DeliveryProvider::where('code', $code)->first();

            $result = $driver->createShipment($this->buildPayload($order, $provider?->id));

            $tracking = $result['tracking_number'] ?? $result['reference'] ?? null;
            $externalId = $result['external_id'] ?? null;

            if (($result['status'] ?? null) !== 'created' || ($tracking === null && $externalId === null)) {
                Log::warning('Opost createShipment did not return a shipment', ['order' => $order->id, 'result' => $result]);

                return ['status' => 'failed', 'message' => $result['message'] ?? __('لم تُرجِع شركة التوصيل رقم تتبّع.')];
            }

            $this->persist($order, $provider?->id, $tracking, $externalId, $result);

            return ['status' => 'created', 'tracking_number' => $tracking];
        } catch (\Throwable $e) {
            Log::warning('Order delivery dispatch failed: '.$e->getMessage(), ['order' => $order->id]);

            return ['status' => 'failed', 'message' => $e->getMessage()];
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
        $order->loadMissing('items.variant.product');

        $qty = (int) max(1, (int) round($order->items->sum(fn ($i) => (float) $i->qty)));
        $description = $order->items
            ->map(fn ($i) => trim(($i->variant?->product?->name ?? '').' ×'.rtrim(rtrim((string) $i->qty, '0'), '.')))
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
        ];
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
    private function persist(Order $order, ?int $providerId, ?string $tracking, ?string $externalId, array $result): void
    {
        DB::transaction(function () use ($order, $providerId, $tracking, $externalId, $result) {
            $order->update([
                'tracking_number' => $tracking,
                'delivery_external_id' => $externalId,
                'delivery_status' => $result['provider_status'] ?? 'submitted',
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
