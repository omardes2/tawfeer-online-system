<?php

namespace App\Modules\Shipping\Services;

use App\Models\User;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Models\ShipmentFeeComponent;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * محرّك رسوم التوصيل (Phase 4.3 / ADR-039) — مكوّنات **مستقلّة عن المزوّد** وقابلة للتوسّع:
 * توصيل/COD/حجم زائد/وزن زائد/منطقة نائية/إرجاع/استبدال/إعادة محاولة/يدوي/خصم.
 */
class DeliveryFeeService
{
    /** إضافة مكوّن رسم للشحنة (مستقلّ عن المزوّد). */
    public function addComponent(Shipment $shipment, string $type, float $amount, array $opts = []): ShipmentFeeComponent
    {
        $this->assertVocab($type, $opts);

        return $shipment->feeComponents()->create([
            'type' => $type,
            'amount' => round($amount, 2),
            'owner' => $opts['owner'] ?? 'business',
            'source' => $opts['source'] ?? 'manual',
            'note' => $opts['note'] ?? null,
            'created_by' => $opts['actor']?->id ?? auth()->id(),
        ]);
    }

    /**
     * ضبط عدّة مكوّنات دفعةً (يستبدل المكوّنات ذات المصدر نفسه — لقطة مزوّد مثلًا).
     *
     * @param  array<int, array{type: string, amount: float, owner?: string, note?: string}>  $components
     */
    public function setQuoted(Shipment $shipment, array $components, ?User $actor = null): void
    {
        $shipment->feeComponents()->where('source', 'quoted')->delete();
        foreach ($components as $c) {
            $this->addComponent($shipment, $c['type'], $c['amount'], [
                'owner' => $c['owner'] ?? 'customer', 'source' => 'quoted',
                'note' => $c['note'] ?? null, 'actor' => $actor,
            ]);
        }
    }

    /** إجمالي الرسوم (الخصم سالب). */
    public function total(Shipment $shipment): float
    {
        return round((float) $shipment->feeComponents()->sum('amount'), 2);
    }

    /**
     * تفصيل الإجمالي حسب المالك (business/customer/provider) — لتوزيع التسوية لاحقًا.
     *
     * @return array<string, float>
     */
    public function totalsByOwner(Shipment $shipment): array
    {
        return $shipment->feeComponents()
            ->selectRaw('owner, SUM(amount) as total')->groupBy('owner')
            ->pluck('total', 'owner')
            ->map(fn ($v) => round((float) $v, 2))
            ->toArray();
    }

    /** @return Collection<int, ShipmentFeeComponent> */
    public function components(Shipment $shipment): Collection
    {
        return $shipment->feeComponents()->get();
    }

    private function assertVocab(string $type, array $opts): void
    {
        $cfg = config('delivery.fees');
        if (! in_array($type, $cfg['types'], true)) {
            throw ValidationException::withMessages(['type' => __('نوع رسم غير مدعوم: :t', ['t' => $type])]);
        }
        if (isset($opts['owner']) && ! in_array($opts['owner'], $cfg['owners'], true)) {
            throw ValidationException::withMessages(['owner' => __('مالك رسم غير مدعوم.')]);
        }
        if (isset($opts['source']) && ! in_array($opts['source'], $cfg['sources'], true)) {
            throw ValidationException::withMessages(['source' => __('مصدر رسم غير مدعوم.')]);
        }
    }
}
