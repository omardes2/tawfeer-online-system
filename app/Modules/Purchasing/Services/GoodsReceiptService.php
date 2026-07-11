<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseOrderItem;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * استلام البضاعة (GRN — BR-PUR-04/05/06). الترحيل يمرّ حصريًا عبر InventoryService.
 */
class GoodsReceiptService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    /**
     * @param  array<int, array{variant_id:int, qty_received:float, unit_cost:float, purchase_order_item_id?:int}>  $items
     */
    public function create(PurchaseOrder $po, array $data, array $items, int $year): GoodsReceipt
    {
        // لا استلام قبل الاعتماد (BR-PUR-03).
        if (! in_array($po->status, ['approved', 'partially_received'], true)) {
            throw ValidationException::withMessages(['purchase_order' => __('لا يمكن الاستلام قبل اعتماد أمر الشراء.')]);
        }

        return DB::transaction(function () use ($po, $data, $items, $year) {
            $receipt = GoodsReceipt::create([
                'number' => NumberGenerator::next('goods_receipts', 'number', 'GRN', $year),
                'purchase_order_id' => $po->id,
                'warehouse_id' => $po->warehouse_id,
                'branch_id' => $po->branch_id,
                'status' => 'draft',
                'additional_cost' => $data['additional_cost'] ?? 0,
                'received_by' => auth()->id(),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $receipt->items()->create([
                    'purchase_order_item_id' => $item['purchase_order_item_id'] ?? null,
                    'variant_id' => $item['variant_id'],
                    'qty_received' => $item['qty_received'],
                    'unit_cost' => $item['unit_cost'],
                ]);
            }

            return $receipt;
        });
    }

    /** الترحيل: يرفع المخزون عبر المحرّك، يوزّع التكلفة المحمّلة، ويحدّث أمر الشراء. */
    public function post(GoodsReceipt $receipt): GoodsReceipt
    {
        $this->assertStatus($receipt, ['draft']);

        return DB::transaction(function () use ($receipt) {
            $receipt->loadMissing(['items', 'warehouse', 'purchaseOrder.items']);
            $warehouse = $receipt->warehouse;

            $lineValues = $receipt->items->mapWithKeys(fn ($i) => [$i->id => (float) $i->qty_received * (float) $i->unit_cost]);
            $totalValue = $lineValues->sum();
            $additional = (float) $receipt->additional_cost;

            foreach ($receipt->items as $item) {
                $qty = (float) $item->qty_received;
                if ($qty <= 0) {
                    continue;
                }

                // توزيع التكلفة المحمّلة بنسبة قيمة السطر (BR-PUR-06).
                $allocated = ($totalValue > 0 && $additional > 0) ? $additional * ($lineValues[$item->id] / $totalValue) : 0.0;
                $landedUnitCost = (float) $item->unit_cost + ($qty > 0 ? $allocated / $qty : 0);
                $item->update(['landed_unit_cost' => $landedUnitCost]);

                $variant = ProductVariant::findOrFail($item->variant_id);
                $this->inventory->receive($variant, $warehouse, $qty, $landedUnitCost, [
                    'reference_type' => GoodsReceipt::class,
                    'reference_id' => $receipt->id,
                    'reason' => 'goods_receipt:'.$receipt->number,
                ]);

                // تحديث الكمية المستلمة على بند أمر الشراء (BR-PUR-05).
                if ($item->purchase_order_item_id) {
                    PurchaseOrderItem::whereKey($item->purchase_order_item_id)->increment('qty_received', $qty);
                }
            }

            $receipt->update(['status' => 'posted', 'received_at' => now(), 'posted_at' => now()]);

            if ($receipt->purchaseOrder) {
                $this->purchaseOrders->recomputeReceiptStatus($receipt->purchaseOrder->fresh());
            }

            return $receipt;
        });
    }

    public function cancel(GoodsReceipt $receipt): GoodsReceipt
    {
        $this->assertStatus($receipt, ['draft']);
        $receipt->update(['status' => 'cancelled']);

        return $receipt;
    }

    private function assertStatus(GoodsReceipt $receipt, array $allowed): void
    {
        if (! in_array($receipt->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('العملية غير مسموحة على إيصال بحالة :status.', ['status' => $receipt->status])]);
        }
    }
}
