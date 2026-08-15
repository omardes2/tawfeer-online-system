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

        $combos = $request->validated('combos', []);
        $entered = round(collect($combos)->sum(fn ($c) => (float) ($c['stock'] ?? 0)), 3);
        $original = $this->originalQuantity($product);

        // المصفوفة **توزّع** كمية الصنف ولا تضيف إليها: مجموع المتغيّرات = العدد الأصلي.
        // (بدون هذا الشرط تُحتسب كمية المتغيّر الافتراضي مرّتين فيتضخّم رصيد المخزن.)
        if ($combos !== [] && $original > 0 && abs($entered - $original) > 0.001) {
            return back()->withInput()->with('error', __(
                'مجموع كميات المتغيّرات (:sum) يجب أن يساوي كمية الصنف (:total). الفرق: :diff.',
                ['sum' => $this->num($entered), 'total' => $this->num($original), 'diff' => $this->num($entered - $original)],
            ));
        }

        $rows = $this->service->sync($product, $combos);

        foreach ($rows as $row) {
            $this->setStock($row['variant'], $row['stock']);
        }

        // تصفير المتغيّر الافتراضي: كميته وُزّعت على المقاسات فلا تُحتسب مرّة ثانية.
        if ($rows->isNotEmpty()) {
            $default = $product->variants()->whereDoesntHave('attributeValues')->first();
            if ($default) {
                $this->setStock($default, 0);
            }
        }

        return redirect()->route('admin.products.edit', $product)
            ->with('success', __('تم حفظ المتغيّرات (:n).', ['n' => $rows->count()]));
    }

    /**
     * «العدد الأصلي» للصنف: **مجموع رصيد كل متغيّراته** — الافتراضي والمقاسات معًا.
     * فالمصفوفة توزّع كامل ما يملكه الصنف ولا تضيف إليه.
     *
     * الجمع يصحّ في الحالات الثلاث: قبل التوزيع (الرصيد كلّه على الافتراضي)، وبعده
     * (الافتراضي صفر)، وفي الحالة التي تجمعهما — حين تُدخل فاتورةُ شراء بضاعةً على
     * المتغيّر الافتراضي لصنفٍ وُزِّعت مقاساته سابقًا. وقصْرُ الحساب على أحد الطرفين
     * كان يُخفي رصيد الطرف الآخر فيتعذّر حفظ المصفوفة أصلًا.
     */
    private function originalQuantity(Product $product): float
    {
        $warehouseId = $this->warehouseId();
        if ($warehouseId === null) {
            return 0.0;
        }

        return round((float) InventoryStock::whereIn('variant_id', $product->variants()->pluck('id'))
            ->where('warehouse_id', $warehouseId)->sum('on_hand'), 3);
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function warehouseId(): ?int
    {
        return Warehouse::where('is_default', true)->value('id') ?? Warehouse::orderBy('id')->value('id');
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
