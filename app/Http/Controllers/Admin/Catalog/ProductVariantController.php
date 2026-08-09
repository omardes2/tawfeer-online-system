<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\GenerateVariantsRequest;
use App\Http\Requests\Catalog\UpdateVariantRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\VariantService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use Illuminate\Http\RedirectResponse;

/**
 * إدارة متغيّرات المنتج (مقاسات/ألوان): توليد التركيبات، تعديل السعر/المخزون/التفعيل، الحذف.
 * كل عمليات المخزون تمرّ عبر InventoryService (ADR-024) لضمان الحركة والتكلفة.
 */
class ProductVariantController extends Controller
{
    public function __construct(
        private readonly VariantService $service,
        private readonly InventoryService $inventory,
    ) {}

    public function generate(GenerateVariantsRequest $request, Product $product): RedirectResponse
    {
        $this->authorize('update', $product);

        $count = $this->service->generate($product, $request->validated('value_ids'));

        return redirect()->route('admin.products.edit', $product)->with(
            'success',
            $count > 0
                ? __('تم توليد :n متغيّرًا.', ['n' => $count])
                : __('لا متغيّرات جديدة — كل التركيبات المختارة موجودة مسبقًا.'),
        );
    }

    public function update(UpdateVariantRequest $request, Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($variant->product_id === $product->id, 404);

        $variant->update([
            'retail_price' => $request->validated('retail_price') ?? 0,
            'promo_price' => $request->validated('promo_price'),
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($request->filled('stock')) {
            $this->setStock($variant, (float) $request->validated('stock'));
        }

        return redirect()->route('admin.products.edit', $product)->with('success', __('تم تحديث المتغيّر.'));
    }

    public function destroy(Product $product, ProductVariant $variant): RedirectResponse
    {
        $this->authorize('update', $product);
        abort_unless($variant->product_id === $product->id, 404);

        $this->service->delete($variant);

        return redirect()->route('admin.products.edit', $product)->with('success', __('تم حذف المتغيّر.'));
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
