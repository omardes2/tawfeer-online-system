<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryCount;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * الحدّ الفعّال لتنبيه النقص، بالترتيب: سطر المخزون ← المتغيّر ← المنتج ← الافتراضي
     * من الإعدادات.
     *
     * كان الشرط `reorder_level IS NOT NULL` على سطر المخزون وحده، وسطور المخزون
     * تُنشأ بلا هذا الحقل ولا تكتبه أي شاشة — فكانت الصفحة فارغة دائمًا مهما بلغ
     * النقص. سلسلة التراجع تجعلها تعمل بلا إعداد مسبق، وتظلّ قابلة للضبط لكل صنف.
     */
    public static function reorderLevelExpression(): string
    {
        return 'COALESCE(inventory_stocks.reorder_level, product_variants.reorder_level, products.reorder_level, '
            .self::defaultReorderLevel().')';
    }

    /** الحدّ الافتراضي من الإعدادات — رقم موجب فقط، وإلا صفر (أي: نافد المخزون). */
    public static function defaultReorderLevel(): float
    {
        return max(0, (float) Settings::get('inventory.default_reorder_level', 0));
    }

    /** استعلام أصناف تحت الحدّ، مربوطًا بالمتغيّر والمنتج لقراءة حدودهما. */
    private function lowStockQuery(Warehouse $warehouse): Builder
    {
        return InventoryStock::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_stocks.variant_id')
            ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
            ->where('inventory_stocks.warehouse_id', $warehouse->id)
            ->whereNull('product_variants.deleted_at')
            ->whereNull('products.deleted_at')
            ->whereRaw('inventory_stocks.on_hand <= '.self::reorderLevelExpression());
    }

    /** أصناف تحت حدّ إعادة الطلب (تنبيهات نقص). */
    public function lowStock(Warehouse $warehouse): Collection
    {
        return $this->lowStockQuery($warehouse)
            ->with('variant.product')
            ->orderBy('inventory_stocks.on_hand')
            // الحدّ الفعّال يُعرض في الصفحة، فيعرف المستخدم من أين جاء الرقم.
            ->select('inventory_stocks.*')
            ->selectRaw(self::reorderLevelExpression().' as effective_reorder_level')
            ->get();
    }

    public function lowStockCount(Warehouse $warehouse): int
    {
        return $this->lowStockQuery($warehouse)->count();
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
