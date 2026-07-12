<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryCountItem;
use App\Modules\Inventory\Models\InventoryStock;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * محرّك جرد المخزون الفعلي — Stock-take (Phase 5 / ADR-043).
 *
 * counting → review → completed. **الإكمال يطبّق الفروق حصريًا عبر `InventoryService`**
 * (adjustIn/adjustOut) فتُسجَّل الحركة + الدفتر + التدقيق تلقائيًا — **لا يمسّ الأرصدة مباشرة
 * ولا يكرّر منطق المخزون**. يدعم الإدخال بالباركود والدفعات.
 */
class InventoryCountService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * فتح جرد: لقطة أرصدة النظام. `$variantIds` فارغة = جرد كامل لكل أصناف المستودع.
     *
     * @param  array<int, int>  $variantIds
     */
    public function open(Warehouse $warehouse, array $variantIds = [], string $scope = 'full', ?User $actor = null): InventoryCount
    {
        return DB::transaction(function () use ($warehouse, $variantIds, $scope, $actor) {
            $count = InventoryCount::create([
                'number' => NumberGenerator::next('inventory_counts', 'number', 'CNT', (int) now()->year),
                'warehouse_id' => $warehouse->id,
                'status' => 'counting',
                'scope' => $scope,
                'counted_by' => $actor?->id ?? auth()->id(),
                'created_by' => auth()->id(),
            ]);

            $stocks = InventoryStock::where('warehouse_id', $warehouse->id)
                ->when(! empty($variantIds), fn ($q) => $q->whereIn('variant_id', $variantIds))
                ->get()->keyBy('variant_id');

            // عند تحديد أصناف غير موجودة كمخزون، تُدرَج بلقطة 0.
            $ids = ! empty($variantIds) ? $variantIds : $stocks->keys()->all();
            foreach ($ids as $variantId) {
                $count->items()->create([
                    'variant_id' => $variantId,
                    'system_qty' => (float) ($stocks[$variantId]->on_hand ?? 0),
                ]);
            }

            return $count->fresh('items');
        });
    }

    /** تسجيل العدّ الفعلي لبند (بالصنف). */
    public function recordCount(InventoryCount $count, int $variantId, float $countedQty, ?string $note = null): InventoryCountItem
    {
        $this->assertCounting($count);
        $item = $count->items()->where('variant_id', $variantId)->first();
        if ($item === null) {
            // صنف خارج نطاق الجرد الأولي (مضاف أثناء العدّ) — أدرِجه بلقطة الحالي.
            $item = $count->items()->create([
                'variant_id' => $variantId,
                'system_qty' => (float) (InventoryStock::where('variant_id', $variantId)->where('warehouse_id', $count->warehouse_id)->value('on_hand') ?? 0),
            ]);
        }
        $item->update([
            'counted_qty' => $countedQty,
            'variance' => round($countedQty - (float) $item->system_qty, 3),
            'note' => $note,
            'counted_at' => now(),
        ]);

        return $item;
    }

    /** تسجيل العدّ بالباركود (مسح). */
    public function recordByBarcode(InventoryCount $count, string $barcode, float $countedQty, ?string $note = null): InventoryCountItem
    {
        $variant = $this->warehouseService->findByBarcode($barcode);
        if ($variant === null) {
            throw ValidationException::withMessages(['barcode' => __('باركود غير معروف: :b', ['b' => $barcode])]);
        }

        return $this->recordCount($count, $variant->id, $countedQty, $note);
    }

    /**
     * إدخال دفعة من نتائج العدّ.
     *
     * @param  array<int, array{variant_id:int, counted_qty:float, note?:string}>  $entries
     */
    public function recordBatch(InventoryCount $count, array $entries): int
    {
        $this->assertCounting($count);

        return DB::transaction(function () use ($count, $entries) {
            $n = 0;
            foreach ($entries as $e) {
                $this->recordCount($count, (int) $e['variant_id'], (float) $e['counted_qty'], $e['note'] ?? null);
                $n++;
            }

            return $n;
        });
    }

    /** إغلاق العدّ ومراجعة الفروق. counting → review. */
    public function review(InventoryCount $count, ?User $actor = null): InventoryCount
    {
        $this->transition($count, 'review', fn () => $count->update(['reviewed_by' => $actor?->id ?? auth()->id()]));

        return $count;
    }

    /** إعادة العدّ. review → counting. */
    public function reopen(InventoryCount $count): InventoryCount
    {
        $this->transition($count, 'counting');

        return $count;
    }

    /**
     * الإكمال: تطبيق الفروق عبر محرّك المخزون (adjustIn/adjustOut) — كل حركة مسجَّلة. review → completed.
     */
    public function complete(InventoryCount $count, ?User $actor = null): InventoryCount
    {
        $this->transition($count, 'completed', function () use ($count) {
            $count->load('items'); // إعادة تحميل بعد تسجيل العدّ (القيم قديمة في الذاكرة).
            $warehouse = $count->warehouse;
            $applied = 0;

            foreach ($count->items as $item) {
                if ($item->counted_qty === null) {
                    continue; // بند لم يُعَدّ → لا تسوية.
                }
                $variant = ProductVariant::find($item->variant_id);
                if ($variant === null) {
                    continue;
                }
                // الفرق مقابل الرصيد الحالي (يضمن أن يصبح on_hand = المعدود مهما تغيّر).
                $current = (float) (InventoryStock::where('variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('on_hand') ?? 0);
                $delta = round((float) $item->counted_qty - $current, 3);

                $opts = ['reference_type' => InventoryCount::class, 'reference_id' => $count->id, 'reason' => 'count:'.$count->number];
                if ($delta > 0) {
                    $this->inventory->adjustIn($variant, $warehouse, $delta, null, $opts);
                } elseif ($delta < 0) {
                    $this->inventory->adjustOut($variant, $warehouse, abs($delta), $opts);
                }
                if (abs($delta) > 1e-9) {
                    $item->update(['applied' => true]);
                    $applied++;
                }
            }

            $count->update(['adjusted_count' => $applied, 'completed_at' => now()]);
        });

        return $count;
    }

    public function cancel(InventoryCount $count): InventoryCount
    {
        $this->transition($count, 'cancelled');

        return $count;
    }

    // ————————————————————————————————— داخلي —————————————————————————————————

    private function transition(InventoryCount $count, string $to, ?callable $effect = null): void
    {
        $from = $count->status;
        if (! InventoryCount::canTransition($from, $to)) {
            throw ValidationException::withMessages(['status' => __('انتقال غير مسموح: :from → :to.', ['from' => $from, 'to' => $to])]);
        }
        DB::transaction(function () use ($count, $to, $effect) {
            if ($effect) {
                $effect();
            }
            $count->update(['status' => $to]);
        });
    }

    private function assertCounting(InventoryCount $count): void
    {
        if ($count->status !== 'counting') {
            throw ValidationException::withMessages(['status' => __('لا يمكن إدخال العدّ إلا أثناء مرحلة العدّ.')]);
        }
    }
}
