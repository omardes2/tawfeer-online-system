<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\SupplierReturn;
use App\Support\NumberGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * مرتجعات المشتريات (BR-PUR-11). الترحيل يخفّض المخزون عبر InventoryService (صادر).
 */
class SupplierReturnService
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @param  array<int, array{variant_id:int, qty:float, unit_cost?:float}>  $items
     */
    public function create(array $data, array $items, int $year): SupplierReturn
    {
        return DB::transaction(function () use ($data, $items, $year) {
            $return = SupplierReturn::create([
                'number' => NumberGenerator::next('supplier_returns', 'number', 'PRET', $year),
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $data['warehouse_id'],
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'status' => 'draft',
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $item) {
                $return->items()->create([
                    'variant_id' => $item['variant_id'],
                    'qty' => $item['qty'],
                    'unit_cost' => $item['unit_cost'] ?? 0,
                ]);
            }

            return $return;
        });
    }

    public function approve(SupplierReturn $return): SupplierReturn
    {
        $this->assertStatus($return, ['draft']);
        $return->update(['status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

        return $return;
    }

    public function cancel(SupplierReturn $return): SupplierReturn
    {
        $this->assertStatus($return, ['draft', 'approved']);
        $return->update(['status' => 'cancelled']);

        return $return;
    }

    /** الترحيل: حركة صادرة تخفّض on_hand لكل بند (BR-PUR-11). */
    public function post(SupplierReturn $return): SupplierReturn
    {
        $this->assertStatus($return, ['approved']);

        return DB::transaction(function () use ($return) {
            $return->loadMissing(['items', 'warehouse']);
            $warehouse = $return->warehouse;

            foreach ($return->items as $item) {
                $qty = (float) $item->qty;
                if ($qty <= 0) {
                    continue;
                }

                $variant = ProductVariant::findOrFail($item->variant_id);
                $this->inventory->purchaseReturn($variant, $warehouse, $qty, [
                    'reference_type' => SupplierReturn::class,
                    'reference_id' => $return->id,
                    'reason' => 'supplier_return:'.$return->number,
                ]);
            }

            $return->update(['status' => 'posted', 'posted_at' => now()]);

            return $return;
        });
    }

    private function assertStatus(SupplierReturn $return, array $allowed): void
    {
        if (! in_array($return->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => __('العملية غير مسموحة على مرتجع بحالة :status.', ['status' => $return->status])]);
        }
    }
}
