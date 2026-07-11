<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * تسويات الجرد (§26): draft → pending_approval → approved → posted (+cancelled).
 * عند post تُطبَّق الفروقات على المخزون كحركات adjustment_in/out + قيود دفتر.
 */
class StockAdjustmentService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<int, array{variant_id:int, qty_counted:float, unit_cost?:float, note?:string}>  $items
     */
    public function create(array $data, array $items, int $year): StockAdjustment
    {
        return DB::transaction(function () use ($data, $items, $year) {
            $adjustment = StockAdjustment::create([
                'number' => NumberGenerator::next('stock_adjustments', 'number', 'ADJ', $year),
                'branch_id' => $data['branch_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'type' => $data['type'] ?? 'recount',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            $this->syncItems($adjustment, $items);

            return $adjustment;
        });
    }

    public function update(StockAdjustment $adjustment, array $data, ?array $items = null): StockAdjustment
    {
        $this->assertEditable($adjustment);

        return DB::transaction(function () use ($adjustment, $data, $items) {
            $adjustment->update(array_intersect_key($data, array_flip(['type', 'reason', 'notes', 'warehouse_id'])));

            if ($items !== null) {
                $adjustment->items()->delete();
                $this->syncItems($adjustment, $items);
            }

            return $adjustment;
        });
    }

    public function submit(StockAdjustment $adjustment): StockAdjustment
    {
        $this->assertStatus($adjustment, ['draft']);
        $adjustment->update(['status' => 'pending_approval']);

        return $adjustment;
    }

    public function approve(StockAdjustment $adjustment): StockAdjustment
    {
        $this->assertStatus($adjustment, ['draft', 'pending_approval']);
        $adjustment->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $adjustment;
    }

    public function cancel(StockAdjustment $adjustment): StockAdjustment
    {
        $this->assertStatus($adjustment, ['draft', 'pending_approval', 'approved']);
        $adjustment->update(['status' => 'cancelled']);

        return $adjustment;
    }

    /** يطبّق فروقات البنود على المخزون (لا يُعاد). */
    public function post(StockAdjustment $adjustment): StockAdjustment
    {
        $this->assertStatus($adjustment, ['approved']);

        return DB::transaction(function () use ($adjustment) {
            $warehouse = $adjustment->warehouse;

            foreach ($adjustment->items as $item) {
                $diff = (float) $item->qty_diff;
                if (abs($diff) < 1e-9) {
                    continue;
                }

                $variant = ProductVariant::findOrFail($item->variant_id);
                $opts = ['reference_type' => StockAdjustment::class, 'reference_id' => $adjustment->id, 'reason' => 'adjustment:'.$adjustment->number];

                if ($diff > 0) {
                    $this->inventory->adjustIn($variant, $warehouse, $diff, (float) $item->unit_cost ?: null, $opts);
                } else {
                    $this->inventory->adjustOut($variant, $warehouse, abs($diff), $opts);
                }
            }

            $adjustment->update(['status' => 'posted', 'posted_at' => now()]);

            return $adjustment;
        });
    }

    private function syncItems(StockAdjustment $adjustment, array $items): void
    {
        foreach ($items as $item) {
            $before = (float) (InventoryStock::where('variant_id', $item['variant_id'])
                ->where('warehouse_id', $adjustment->warehouse_id)->value('on_hand') ?? 0);
            $counted = (float) $item['qty_counted'];

            $adjustment->items()->create([
                'variant_id' => $item['variant_id'],
                'qty_before' => $before,
                'qty_counted' => $counted,
                'qty_diff' => $counted - $before,
                'unit_cost' => $item['unit_cost'] ?? 0,
                'note' => $item['note'] ?? null,
            ]);
        }
    }

    private function assertEditable(StockAdjustment $adjustment): void
    {
        $this->assertStatus($adjustment, ['draft', 'pending_approval']);
    }

    private function assertStatus(StockAdjustment $adjustment, array $allowed): void
    {
        if (! in_array($adjustment->status, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => __('العملية غير مسموحة على تسوية بحالة :status.', ['status' => $adjustment->status]),
            ]);
        }
    }
}
