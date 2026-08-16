<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
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

        $products = Product::query()
            ->with('category:id,name')
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
            // الفئات التي فيها أصناف فعلًا: فئة فارغة في الفلتر تعطي صفحة خاوية.
            'categories' => Category::query()
                ->whereIn('id', Product::query()->whereNotNull('category_id')->distinct()->pluck('category_id'))
                ->orderBy('name')->get(['id', 'name']),
            'activeCategory' => $category,
        ]);
    }
}
