<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\InventoryStock;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * الأصناف والأسعار — قائمة أسعار للقراءة فقط.
 *
 * وُجدت للمسوّق: يحتاج أن يعرف ما يبيعه وبكم (بيعًا وجملةً) بلا أن يُفتح له
 * الكتالوج نفسه. ولذلك لا يعرض **التكلفة** ولا المخزون ولا أي إجراء — هي شاشة
 * اطّلاع لا إدارة، وصلاحيتها `catalog.price_list.view` مستقلّة عن `catalog.*`.
 */
class PriceListController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('catalog.price_list.view');

        $category = $request->integer('category') ?: null;

        // المسوّق لا يرى الممنوع عليه؛ والمدير يراه موسومًا ليعرف ما يخفيه عنهم.
        $hidesUnavailable = $request->user()?->sellsAsAffiliate() ?? false;

        $products = Product::query()
            ->when($hidesUnavailable, fn ($q) => $q->availableToAffiliates())
            ->with(['category:id,name', 'primaryImage', 'variants.attributeValues', 'defaultVariant'])
            // السعران من المتغيّرات: المنتج ذو المقاسات قد تختلف أسعاره بينها،
            // وافتراضيُّه حاملٌ مجرَّد بلا سعر غالبًا — فيُقرأ الحدّان لا رقمُه.
            ->withMin('variants', 'retail_price')
            ->withMax('variants', 'retail_price')
            ->withMin('variants', 'wholesale_price')
            ->withMax('variants', 'wholesale_price')
            ->when($category, fn ($q) => $q->where('category_id', $category))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', '%'.$request->string('search').'%')
                ->orWhere('sku', 'like', '%'.$request->string('search').'%')))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.catalog.price_list', [
            'products' => $products,
            // المتاح لكل متغيّر (المعروض فقط) باستعلامٍ واحد لا استعلامٍ لكل صفّ.
            // «المتاح» = الموجود ناقص المحجوز: المحجوز مباعٌ فعلًا وإن لم يخرج بعد.
            'available' => $this->availableByVariant($products->getCollection()),
            // الفئات التي فيها أصناف فعلًا: فئة فارغة في الفلتر تعطي صفحة خاوية.
            'categories' => Category::query()
                ->whereIn('id', Product::query()->whereNotNull('category_id')->distinct()->pluck('category_id'))
                ->orderBy('name')->get(['id', 'name']),
            'activeCategory' => $category,
        ]);
    }

    /**
     * الكميات المتاحة لمتغيّرات الصفحة المعروضة، مفهرسةً بـ variant_id.
     *
     * تُجمَع من كل المستودعات كعمود «المتوفّرة» في صفحة المنتجات — السؤال هنا
     * «هل يوجد؟» لا «في أي مستودع؟».
     *
     * @param  Collection<int, Product>  $products
     */
    private function availableByVariant($products): Collection
    {
        $ids = $products->flatMap(fn (Product $p) => $p->variants->pluck('id'))->unique();

        if ($ids->isEmpty()) {
            return collect();
        }

        return InventoryStock::query()
            ->whereIn('variant_id', $ids)
            ->groupBy('variant_id')
            ->selectRaw('variant_id, SUM(on_hand - reserved) as qty')
            ->pluck('qty', 'variant_id');
    }
}
