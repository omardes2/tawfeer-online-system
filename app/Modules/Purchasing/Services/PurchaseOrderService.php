<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * أوامر الشراء (BR-PUR-02/03/12، ADR-025).
 * draft → pending_approval → approved → partially_received → received → closed (+cancelled).
 */
class PurchaseOrderService
{
    /**
     * @param  array<int, array{variant_id:int, qty_ordered:float, unit_cost:float, discount?:float}>  $items
     */
    public function create(array $data, array $items, int $year): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $items, $year) {
            $po = PurchaseOrder::create([
                'number' => NumberGenerator::next('purchase_orders', 'number', 'PO', $year),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'branch_id' => $data['branch_id'] ?? null,
                'status' => 'draft',
                'order_date' => $data['order_date'] ?? now()->toDateString(),
                'expected_date' => $data['expected_date'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->syncItems($po, $items);
            $this->recomputeTotals($po);

            return $po;
        });
    }

    public function update(PurchaseOrder $po, array $data, ?array $items = null): PurchaseOrder
    {
        $this->assertStatus($po, ['draft', 'pending_approval']); // قابل للتعديل حتى الاعتماد (BR-PUR-02)

        return DB::transaction(function () use ($po, $data, $items) {
            $po->update(array_intersect_key($data, array_flip(['supplier_id', 'warehouse_id', 'branch_id', 'order_date', 'expected_date', 'notes'])));

            if ($items !== null) {
                $po->items()->delete();
                $this->syncItems($po, $items);
                $this->recomputeTotals($po);
            }

            return $po;
        });
    }

    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertStatus($po, ['draft']);
        $po->update(['status' => 'pending_approval']);

        return $po;
    }

    public function approve(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertStatus($po, ['draft', 'pending_approval']);
        $po->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $po;
    }

    public function cancel(PurchaseOrder $po): PurchaseOrder
    {
        // الإلغاء قبل أي استلام فقط (BR-PUR-12).
        $this->assertStatus($po, ['draft', 'pending_approval', 'approved']);
        if ($po->receipts()->where('status', 'posted')->exists()) {
            throw ValidationException::withMessages(['status' => __('لا يمكن إلغاء أمر بدأ استلامه؛ عالِج ما استُلم عبر مرتجع.')]);
        }
        $po->update(['status' => 'cancelled']);

        return $po;
    }

    public function close(PurchaseOrder $po): PurchaseOrder
    {
        $this->assertStatus($po, ['received', 'partially_received']);
        $po->update(['status' => 'closed']);

        return $po;
    }

    /** يُعاد احتساب الحالة بعد ترحيل استلام (BR-PUR-05). */
    public function recomputeReceiptStatus(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        $anyReceived = $po->items->sum(fn ($i) => (float) $i->qty_received) > 0;
        $allReceived = $po->items->every(fn ($i) => (float) $i->qty_received >= (float) $i->qty_ordered);

        if ($allReceived && $po->items->isNotEmpty()) {
            $po->update(['status' => 'received']);
        } elseif ($anyReceived) {
            $po->update(['status' => 'partially_received']);
        }
    }

    private function syncItems(PurchaseOrder $po, array $items): void
    {
        foreach ($items as $item) {
            $qty = (float) $item['qty_ordered'];
            $unitCost = (float) $item['unit_cost'];
            $discount = (float) ($item['discount'] ?? 0);

            $po->items()->create([
                'variant_id' => $item['variant_id'],
                'qty_ordered' => $qty,
                'unit_cost' => $unitCost,
                'discount' => $discount,
                'line_total' => ($qty * $unitCost) - $discount,
                'qty_received' => 0,
            ]);
        }
    }

    private function recomputeTotals(PurchaseOrder $po): void
    {
        $po->loadMissing('items');
        $subtotal = $po->items->sum(fn ($i) => (float) $i->qty_ordered * (float) $i->unit_cost);
        $discount = $po->items->sum(fn ($i) => (float) $i->discount);

        $po->update([
            'subtotal' => $subtotal,
            'discount_total' => $discount,
            'total' => $subtotal - $discount,
        ]);
    }

    private function assertStatus(PurchaseOrder $po, array $allowed): void
    {
        if (! in_array($po->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('العملية غير مسموحة على أمر بحالة :status.', ['status' => $po->status])]);
        }
    }
}
