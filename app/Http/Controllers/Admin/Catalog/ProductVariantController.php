<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\SyncVariantsRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\VariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\RedirectResponse;

/**
 * مزامنة مصفوفة متغيّرات المنتج (مقاسات/ألوان) من الواجهة الحيّة: إنشاء/تحديث/حذف
 * التركيبات دفعة واحدة، وضبط السعر والكمية لكل متغيّر. المخزون عبر InventoryService (ADR-024).
 */
class ProductVariantController extends Controller
{
    public function __construct(
        private readonly VariantService $service,
        private readonly InventoryService $inventory,
    ) {}

    public function sync(SyncVariantsRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $rows = $this->service->sync($product, $request->validated('combos', []));

        foreach ($rows as $row) {
            $this->setStock($row['variant'], $row['stock']);
        }

        return redirect()->route('admin.products.edit', $product)
            ->with('success', __('تم حفظ المتغيّرات (:n).', ['n' => $rows->count()]));
    }

    /** يضبط رصيد المتغيّر في المستودع الافتراضي إلى القيمة المطلوبة عبر تسوية (فرق الكمية). */
    private function setStock(ProductVariant $variant, float $target): void
    {
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        if ($warehouse === null) {
            return;
        }

        $current = (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');

        $delta = round($target - $current, 3);
        if (abs($delta) < 1e-9) {
            return;
        }

        if ($delta > 0) {
            $this->inventory->adjustIn($variant, $warehouse, $delta, $variant->cost_price ?: null, ['reason' => 'variant_stock_set']);
        } else {
            $this->inventory->adjustOut($variant, $warehouse, -$delta, ['reason' => 'variant_stock_set']);
        }
    }
}
