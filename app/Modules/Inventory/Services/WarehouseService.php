<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Support\Collection;

/**
 * خدمات مستودع للقراءة (Phase 5 / ADR-043) — تنبيهات نقص، بحث باركود، ولوحة مؤشّرات.
 * **للقراءة فقط**؛ تعيد استخدام نموذج المخزون القائم (reorder_level، available) دون تكرار.
 */
class WarehouseService
{
    /** بحث المتغيّر بالباركود الدقيق (المتغيّر ثم المنتج → متغيّره الافتراضي). */
    public function findByBarcode(string $barcode): ?ProductVariant
    {
        $barcode = trim($barcode);
        if ($barcode === '') {
            return null;
        }

        $variant = ProductVariant::where('barcode', $barcode)->first();
        if ($variant) {
            return $variant;
        }

        return Product::where('barcode', $barcode)->first()?->defaultVariant;
    }

    /** أصناف تحت حدّ إعادة الطلب (تنبيهات نقص) — يعيد استخدام reorder_level القائم. */
    public function lowStock(Warehouse $warehouse): Collection
    {
        return InventoryStock::with('variant.product')
            ->where('warehouse_id', $warehouse->id)
            ->whereNotNull('reorder_level')
            ->whereColumn('on_hand', '<=', 'reorder_level')
            ->orderBy('on_hand')
            ->get();
    }

    public function lowStockCount(Warehouse $warehouse): int
    {
        return InventoryStock::where('warehouse_id', $warehouse->id)
            ->whereNotNull('reorder_level')
            ->whereColumn('on_hand', '<=', 'reorder_level')
            ->count();
    }

    /** مؤشّرات لوحة المستودع. @return array<string, float|int> */
    public function dashboard(Warehouse $warehouse): array
    {
        $stocks = InventoryStock::where('warehouse_id', $warehouse->id);

        return [
            'skus' => (int) (clone $stocks)->count(),
            'on_hand' => round((float) (clone $stocks)->sum('on_hand'), 3),
            'reserved' => round((float) (clone $stocks)->sum('reserved'), 3),
            'available' => round((float) (clone $stocks)->sum('on_hand') - (float) (clone $stocks)->sum('reserved'), 3),
            'damaged' => round((float) (clone $stocks)->sum('damaged'), 3),
            'stock_value' => round((float) (clone $stocks)->selectRaw('SUM(on_hand * average_cost) as v')->value('v'), 2),
            'low_stock' => $this->lowStockCount($warehouse),
            'open_counts' => (int) InventoryCount::where('warehouse_id', $warehouse->id)->whereIn('status', ['counting', 'review'])->count(),
        ];
    }
}
