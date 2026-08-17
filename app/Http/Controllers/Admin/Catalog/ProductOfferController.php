<?php

namespace App\Http\Controllers\Admin\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductOfferRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOffer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * عروض الكمّية على الصنف — تُدار من صفحة تعديل المنتج.
 *
 * ولا يُمنع عرضٌ لأن سعره تحت التكلفة: قد يقصده صاحب المتجر لتصريف مخزون.
 * التحذير في الشاشة، والقرار له — والمنع هنا يُقحم النظام في تسعيرٍ ليس له.
 */
class ProductOfferController extends Controller
{
    public function store(ProductOfferRequest $request, Product $product): RedirectResponse
    {
        $product->offers()->create($request->validated());

        return back()->with('success', __('أُضيف العرض.'));
    }

    public function update(ProductOfferRequest $request, Product $product, ProductOffer $offer): RedirectResponse
    {
        $this->assertOwned($product, $offer);

        $offer->update($request->validated());

        return back()->with('success', __('حُدِّث العرض.'));
    }

    public function destroy(Request $request, Product $product, ProductOffer $offer): RedirectResponse
    {
        abort_unless($request->user()->can('catalog.products.update'), 403);
        $this->assertOwned($product, $offer);

        $offer->delete();

        return back()->with('success', __('حُذف العرض.'));
    }

    /**
     * العرض يخصّ هذا الصنف.
     *
     * الربط المتشعّب (nested) لا يفحص النسبة وحده: معرّف عرضٍ من صنفٍ آخر يمرّ
     * ويُعدَّل — فيتغيّر سعر صنفٍ لم يفتحه أحد.
     */
    private function assertOwned(Product $product, ProductOffer $offer): void
    {
        abort_unless($offer->product_id === $product->id, 404);
    }
}
