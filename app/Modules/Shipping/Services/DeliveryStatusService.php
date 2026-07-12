<?php

namespace App\Modules\Shipping\Services;

use App\Models\User;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Shipping\Events\DeliveryStatusChanged;
use App\Modules\Shipping\Events\ShipmentClosed;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Support\DeliveryStatus;
use App\Support\Integrations\Shipping\DeliveryProviderManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك حالة التوصيل القانوني (Canonical Delivery Status Engine — ADR-038).
 *
 * المبادئ:
 * 1. حالة قانونية داخلية (`delivery_status`) منفصلة تمامًا عن حالة المزوّد الخام
 *    (`provider_status`). تعيين المزوّد ← قانوني يقع حصريًا في طبقة الـDriver.
 * 2. كل انتقال يُسجَّل (سابقة/جديدة/وقت/فاعل/سبب/ملاحظة) — سجلّان منفصلان append-only.
 * 3. أسباب التعليق مُصنّفة وقابلة للتقرير.
 * 4. **CLOSE هي نقطة الاكتمال المالي الوحيدة:** الطلب يصبح مُسوًّى، والعمولات
 *    (مبيعات + مسوّق) تصبح **eligible فقط** — لا دفع تلقائي، الاعتماد/الصرف يبقيان
 *    مسؤولية المالية منفصلين (Phase 4.2).
 * 5. كل المنطق هنا (لا تكرار)؛ متحكّمات رفيعة.
 */
class DeliveryStatusService
{
    public function __construct(
        private readonly CommissionService $commissions,
        private readonly DeliveryProviderManager $providers,
    ) {}

    /**
     * الانتقال القانوني (يدوي/نظام) بين حالتين — مع تحقّق وتسجيل وآثار جانبية.
     *
     * @param  array{source?: string, actor?: ?User, reason?: ?string, note?: ?string, provider_status?: ?string, reference?: ?string}  $opts
     */
    public function transitionTo(Shipment $shipment, string $to, array $opts = []): Shipment
    {
        $from = $shipment->delivery_status;
        $source = $opts['source'] ?? 'user';
        $reason = $opts['reason'] ?? null;

        if (! DeliveryStatus::isValid($to)) {
            throw ValidationException::withMessages(['delivery_status' => __('حالة توصيل غير معروفة: :s.', ['s' => $to])]);
        }
        if (! DeliveryStatus::canTransition($from, $to)) {
            throw ValidationException::withMessages([
                'delivery_status' => __('انتقال توصيل غير مسموح: :from → :to.', ['from' => $from, 'to' => $to]),
            ]);
        }

        // سبب مصنّف إلزامي عند التعليق يدويًا.
        if ($to === DeliveryStatus::ON_HOLD) {
            if ($source === 'user' && ($reason === null || ! DeliveryStatus::isValidReason($reason))) {
                throw ValidationException::withMessages(['reason' => __('سبب التعليق مطلوب ومن القائمة المعتمدة.')]);
            }
            if ($reason !== null && ! DeliveryStatus::isValidReason($reason)) {
                throw ValidationException::withMessages(['reason' => __('سبب التعليق غير معتمد.')]);
            }
        }

        return DB::transaction(function () use ($shipment, $from, $to, $source, $reason, $opts) {
            $shipment->loadMissing('order');

            $attrs = ['delivery_status' => $to];
            if ($to === DeliveryStatus::ON_HOLD) {
                $attrs['on_hold_reason'] = $reason;
            } elseif ($from === DeliveryStatus::ON_HOLD) {
                $attrs['on_hold_reason'] = null; // زال التعليق.
            }
            if ($to === DeliveryStatus::CLOSED) {
                $attrs['closed_at'] = now();
            }
            if (! empty($opts['provider_status'])) {
                $attrs['provider_status'] = $opts['provider_status'];
            }
            $shipment->update($attrs);

            $this->recordTransition($shipment, $from, $to, $source, $opts['actor'] ?? null, $reason, $opts['note'] ?? null, $opts['provider_status'] ?? null);

            // أثر مالي وحيد عند الإغلاق.
            if ($to === DeliveryStatus::CLOSED) {
                $this->applyCloseEffects($shipment, $opts['actor'] ?? null, $opts['reference'] ?? $shipment->number);
            }

            DeliveryStatusChanged::dispatch($shipment, $from, $to, $source, $reason);

            return $shipment;
        });
    }

    // ---- اختصارات قانونية (لا منطق مزوّد) ----

    public function submit(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::READY_FOR_PICKUP, $opts);
    }

    public function cancel(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::CANCELLED, $opts);
    }

    public function pickup(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::PICKED_UP, $opts);
    }

    /** تعليق بسبب مصنّف + ملاحظة (المتطلّب 2). */
    public function putOnHold(Shipment $s, string $reason, ?string $note = null, ?User $actor = null): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::ON_HOLD, ['reason' => $reason, 'note' => $note, 'actor' => $actor]);
    }

    public function resume(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::PICKED_UP, $opts);
    }

    /** سُلّمت للعميل والنقد لدى المندوب — العمولة تبقى pending (ليست مستحقّة). */
    public function markDeliveredCodHeld(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::DELIVERED_COD_HELD, $opts);
    }

    public function markFundsAtAccounting(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::FUNDS_AT_ACCOUNTING, $opts);
    }

    public function markReturningToCourier(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::RETURNING_TO_COURIER, $opts);
    }

    public function markReturnInTransit(Shipment $s, array $opts = []): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::RETURN_IN_TRANSIT, $opts);
    }

    /** الإغلاق المالي النهائي (CLOSE) — يُفعّل التسوية واستحقاق العمولات. */
    public function close(Shipment $s, ?User $actor = null, ?string $reference = null): Shipment
    {
        return $this->transitionTo($s, DeliveryStatus::CLOSED, ['actor' => $actor, 'reference' => $reference, 'source' => 'user']);
    }

    /**
     * استيعاب حالة مزوّد خام (webhook/مزامنة) — idempotent. يسجّل انتقال المزوّد الخام دائمًا،
     * ثم يعيّنها لحالة قانونية عبر الـDriver وينفّذ الانتقال إن كان قانونيًا. أحداث المزوّد
     * قد تصل خارج الترتيب؛ الانتقال غير القانوني يُوثَّق دون رمي استثناء.
     *
     * @param  array{event_id?: ?string, payload?: ?array<string, mixed>, actor?: ?User}  $opts
     */
    public function applyProviderStatus(Shipment $shipment, string $providerStatus, array $opts = []): Shipment
    {
        $eventId = $opts['event_id'] ?? null;

        // idempotency: تجاهل حدث المزوّد المكرّر.
        if ($eventId !== null && $shipment->providerTransitions()
            ->where('delivery_provider_id', $shipment->delivery_provider_id)
            ->where('event_id', $eventId)->exists()) {
            return $shipment->fresh();
        }

        $canonical = $this->providers->forShipment($shipment)->mapProviderStatus($providerStatus);
        $fromProvider = $shipment->provider_status;

        DB::transaction(function () use ($shipment, $providerStatus, $canonical, $fromProvider, $eventId, $opts) {
            $shipment->providerTransitions()->create([
                'delivery_provider_id' => $shipment->delivery_provider_id,
                'from_provider_status' => $fromProvider,
                'to_provider_status' => $providerStatus,
                'canonical_status' => $canonical,
                'event_id' => $eventId,
                'payload' => $opts['payload'] ?? null,
                'received_at' => now(),
                'created_at' => now(),
            ]);
            $shipment->update(['provider_status' => $providerStatus, 'last_synced_at' => now(), 'sync_status' => 'synced']);

            // عيّن الحالة القانونية إن أمكن وكان الانتقال قانونيًا (وإلا وثّق حالة المزوّد فقط).
            if ($canonical !== null
                && $canonical !== $shipment->delivery_status
                && DeliveryStatus::canTransition($shipment->delivery_status, $canonical)) {
                $this->transitionTo($shipment, $canonical, [
                    'source' => 'provider',
                    'actor' => $opts['actor'] ?? null,
                    'provider_status' => $providerStatus,
                ]);
            }
        });

        return $shipment->fresh();
    }

    // ---- التقارير (المتطلّب 3: أسباب التعليق قابلة للتقرير) ----

    /**
     * تجميع أسباب التعليق (عدد مرات كل سبب مصنّف عبر السجلّ القانوني).
     *
     * @return Collection<int, object{reason_code: string, total: int}>
     */
    public function holdReasonReport(): Collection
    {
        return DB::table('delivery_status_transitions')
            ->select('reason_code', DB::raw('COUNT(*) as total'))
            ->where('to_status', DeliveryStatus::ON_HOLD)
            ->whereNotNull('reason_code')
            ->groupBy('reason_code')
            ->orderByDesc('total')
            ->get();
    }

    // ---- الآثار المالية (CLOSE فقط) ----

    /**
     * أثر الإغلاق المالي: تسوية الطلب + جعل العمولات eligible (لا دفع تلقائي).
     * ضمن معاملة الانتقال. idempotent (settled_at مرّة واحدة؛ الاستحقاق يمسّ pending فقط).
     */
    private function applyCloseEffects(Shipment $shipment, ?User $actor, string $reference): void
    {
        $order = $shipment->order;
        if ($order === null) {
            return;
        }

        if ($order->settled_at === null) {
            $order->update(['settled_at' => now()]);
        }

        // ضمان وجود قيود الاستحقاق (idempotent) ثم رفعها إلى eligible — لا دفع تلقائي.
        $this->commissions->accrueForOrder($order);
        $this->commissions->markEligibleForOrder($order, $reference, $actor);

        ShipmentClosed::dispatch($shipment, $order);
    }

    private function recordTransition(Shipment $shipment, ?string $from, string $to, string $source, ?User $actor, ?string $reason, ?string $note, ?string $providerStatus): void
    {
        $shipment->deliveryTransitions()->create([
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $source,
            'actor_id' => $actor?->id ?? ($source === 'user' ? auth()->id() : null),
            'reason_code' => $reason,
            'note' => $note,
            'provider_status' => $providerStatus,
            'created_at' => now(),
        ]);
    }
}
