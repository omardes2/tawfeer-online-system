<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Models\DeliveryProviderEvent;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use Throwable;

/**
 * المزامنة المجدولة لحالات التوصيل (Phase 4.3 / ADR-039): استطلاع **الشحنات النشطة فقط**
 * عبر الـDriver، تسجيل كامل، كشف تعارضات المزوّد، وإعادة محاولة الفاشلة. الاستيعاب يمرّ
 * عبر DeliveryStatusService (لا تكرار). كل تتبّع عبر طبقة تجريد المزوّد.
 */
class DeliverySyncService
{
    public function __construct(
        private readonly DeliveryProviderManager $providers,
        private readonly DeliveryStatusService $delivery,
    ) {}

    /**
     * مزامنة كل الشحنات النشطة (غير النهائية ولها مزوّد).
     *
     * @return array<string, int>
     */
    public function syncActive(): array
    {
        $counts = ['synced' => 0, 'skipped' => 0, 'failed' => 0, 'inconsistent' => 0, 'no_reference' => 0];

        Shipment::whereNotNull('delivery_provider_id')
            ->whereNotIn('delivery_status', DeliveryStatus::TERMINAL)
            ->where('sync_attempts', '<', (int) config('delivery.sync.max_attempts', 5))
            ->chunkById((int) config('delivery.sync.chunk', 100), function ($chunk) use (&$counts) {
                foreach ($chunk as $shipment) {
                    $counts[$this->syncShipment($shipment)]++;
                }
            });

        return $counts;
    }

    /** مزامنة شحنة واحدة — يعيد مفتاح النتيجة. */
    public function syncShipment(Shipment $shipment): string
    {
        $reference = $shipment->external_id ?: $shipment->tracking_number;
        if (empty($reference)) {
            return 'no_reference'; // لم تُرسَل للمزوّد بعد ⇒ لا شيء نستعلم عنه.
        }

        try {
            $driver = $this->providers->forShipment($shipment);
            $result = $driver->track($reference);
            $providerStatus = $result['provider_status'] ?? null;

            // فشل الاستعلام (شبكة/صلاحية/مرجع مجهول) ⇒ فشل صريح لا «تخطٍّ» صامت،
            // وإلا ظهر العطل وكأنّ كل شيء سليم («synced» بلا خطأ).
            if (! empty($result['error'])) {
                $shipment->increment('sync_attempts');
                $shipment->update(['sync_status' => 'failed', 'sync_error' => mb_substr((string) $result['error'], 0, 500)]);
                $this->logEvent($shipment, $providerStatus, $reference, 'failed', mb_substr((string) $result['error'], 0, 500));

                return 'failed';
            }

            // لا حالة أو لم تتغيّر ⇒ لا استيعاب مكرّر (idempotent).
            if ($providerStatus === null || $providerStatus === $shipment->provider_status) {
                $shipment->update(['last_synced_at' => now(), 'sync_status' => 'synced', 'sync_error' => null]);

                return 'skipped';
            }

            $canonical = $driver->mapProviderStatus($providerStatus);

            // تعارض حقيقي: لا انتقال مباشر ولا مسار قانوني وحيد يوصل إليه (قفزة غامضة أو
            // رجوع للخلف) ⇒ تُترك للمراجعة البشرية بدل التخمين. القفزة ذات المسار الوحيد
            // تُستوعَب في DeliveryStatusService بالسير خطوة خطوة.
            if ($canonical !== null && $canonical !== $shipment->delivery_status
                && ! DeliveryStatus::canTransition($shipment->delivery_status, $canonical)
                && DeliveryStatus::path($shipment->delivery_status, $canonical) === null) {
                $this->logEvent($shipment, $providerStatus, $reference, 'inconsistent', "canonical=$canonical illegal from {$shipment->delivery_status}");
                $shipment->update(['last_synced_at' => now(), 'sync_status' => 'inconsistent', 'sync_error' => "provider=$providerStatus → $canonical"]);

                return 'inconsistent';
            }

            $this->delivery->applyProviderStatus($shipment, $providerStatus, ['payload' => $result]);
            $this->logEvent($shipment, $providerStatus, $reference, 'processed');
            $shipment->update(['last_synced_at' => now(), 'sync_status' => 'synced', 'sync_attempts' => 0, 'sync_error' => null]);

            return 'synced';
        } catch (Throwable $e) {
            $shipment->increment('sync_attempts');
            $shipment->update(['sync_status' => 'failed', 'sync_error' => mb_substr($e->getMessage(), 0, 500)]);
            $this->logEvent($shipment, null, $reference, 'failed', mb_substr($e->getMessage(), 0, 500));

            return 'failed';
        }
    }

    private function logEvent(Shipment $shipment, ?string $providerStatus, string $externalId, string $status, ?string $error = null): void
    {
        DeliveryProviderEvent::create([
            'delivery_provider_id' => $shipment->delivery_provider_id,
            'provider_code' => $shipment->provider?->driver,
            'shipment_id' => $shipment->id,
            'channel' => 'sync',
            'external_id' => $externalId,
            'provider_status' => $providerStatus,
            'status' => $status,
            'error' => $error,
            'received_at' => now(),
            'processed_at' => $status === 'processed' ? now() : null,
        ]);
    }
}
