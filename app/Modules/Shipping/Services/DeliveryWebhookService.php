<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Shipping\Models\DeliveryProviderEvent;
use App\Modules\Shipping\Models\Shipment;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use Throwable;

/**
 * بنية الـwebhook للتوصيل (Phase 4.3 / ADR-039): تحقّق توقيع (إن دُعم) + idempotency +
 * حماية من التكرار + تسجيل كامل. كل استيعاب حالة يمرّ عبر DeliveryStatusService (لا تكرار).
 * التطبيع/التوقيع يقعان في الـDriver (طبقة التجريد) — لا منطق مزوّد هنا.
 */
class DeliveryWebhookService
{
    public function __construct(
        private readonly DeliveryProviderManager $providers,
        private readonly DeliveryStatusService $delivery,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function handle(string $providerCode, string $rawPayload, array $payload, array $headers): DeliveryProviderEvent
    {
        $driver = $this->providers->driver($providerCode);
        $providerRecord = DeliveryProvider::where('code', $providerCode)->orWhere('driver', $providerCode)->first();
        $parsed = $driver->parseWebhookEvent($payload);

        // التحقّق من التوقيع (إن دعمه المزوّد وتوفّر سرّ).
        $signatureValid = null;
        if ($driver->supportsWebhookSignature()) {
            $secret = config("delivery.webhook.secrets.$providerCode");
            $signatureValid = $driver->verifyWebhookSignature($rawPayload, $headers, $secret);
        }

        $event = new DeliveryProviderEvent([
            'delivery_provider_id' => $providerRecord?->id,
            'provider_code' => $providerCode,
            'channel' => 'webhook',
            'event_id' => $parsed['event_id'],
            'external_id' => $parsed['external_id'],
            'provider_status' => $parsed['provider_status'],
            'signature_valid' => $signatureValid,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        // توقيع مطلوب وفشل ⇒ رفض وتسجيل.
        if ($signatureValid === false) {
            $event->status = 'unverified';
            $event->error = 'signature verification failed';
            $event->save();

            return $event;
        }

        // حماية من التكرار: نفس (مزوّد، event_id) عُولِج سابقًا.
        if ($parsed['event_id'] !== null && DeliveryProviderEvent::where('provider_code', $providerCode)
            ->where('event_id', $parsed['event_id'])->where('status', 'processed')->exists()) {
            $event->status = 'duplicate';
            $event->save();

            return $event;
        }

        $shipment = $this->resolveShipment($parsed['external_id'], $providerRecord);
        $event->shipment_id = $shipment?->id;

        if ($shipment === null || $parsed['provider_status'] === null) {
            $event->status = $shipment === null ? 'ignored' : 'failed';
            $event->error = $shipment === null ? 'shipment not found' : 'missing provider status';
            $event->save();

            return $event;
        }

        try {
            $this->delivery->applyProviderStatus($shipment, $parsed['provider_status'], [
                'event_id' => $parsed['event_id'],
                'payload' => $payload,
            ]);
            $event->status = 'processed';
            $event->processed_at = now();
        } catch (Throwable $e) {
            $event->status = 'failed';
            $event->error = mb_substr($e->getMessage(), 0, 500);
        }
        $event->save();

        return $event;
    }

    private function resolveShipment(?string $externalId, ?DeliveryProvider $provider): ?Shipment
    {
        if ($externalId === null) {
            return null;
        }

        return Shipment::when($provider, fn ($q) => $q->where('delivery_provider_id', $provider->id))
            ->where(fn ($q) => $q->where('external_id', $externalId)->orWhere('tracking_number', $externalId))
            ->first();
    }
}
