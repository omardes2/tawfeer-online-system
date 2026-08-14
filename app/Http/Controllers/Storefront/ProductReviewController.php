<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\StoreProductReviewRequest;
use App\Modules\Crm\Models\Customer;
use App\Modules\Store\Services\ReviewService;
use App\Modules\Store\Services\StorefrontService;
use Illuminate\Http\RedirectResponse;

/**
 * استقبال تقييم الزبون لمنتج.
 *
 * ثلاثة شروط قبل الحفظ، وكلّها تُفحص في الخادم لا في الواجهة: حساب زبون، وطلب
 * **مستلَم** يحوي المنتج، وألّا يكون قد قيّمه من قبل. يُحفظ `pending` بانتظار
 * مراجعة إدارية — لا شيء يظهر في المتجر من هنا مباشرة.
 */
class ProductReviewController extends Controller
{
    public function __construct(
        private readonly StorefrontService $storefront,
        private readonly ReviewService $reviews,
    ) {}

    public function store(StoreProductReviewRequest $request, string $slug): RedirectResponse
    {
        $product = $this->storefront->findProductBySlug($slug);
        // المرساة تُعيد الزبون إلى القسم نفسه لا إلى أعلى الصفحة.
        $back = route('storefront.product', $product->slug).'#reviews';

        $customer = Customer::where('user_id', $request->user()->id)->first();

        if (! $customer || ! ($order = $this->reviews->purchaseOrder($customer, $product))) {
            return redirect()->to($back)->withErrors(['rating' => __('storefront.review_requires_purchase')]);
        }

        if ($this->reviews->existing($customer, $product)) {
            return redirect()->to($back)->withErrors(['rating' => __('storefront.review_already_sent')]);
        }

        $this->reviews->create($customer, $product, $order, $request->validated());

        return redirect()->to($back)->with('success', __('storefront.review_pending_notice'));
    }
}
