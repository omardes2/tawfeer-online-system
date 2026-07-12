<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Store\Services\WishlistService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * قائمة المفضّلة (Phase 3.4): عرض وتبديل. المنطق في WishlistService.
 */
class WishlistController extends Controller
{
    use InteractsWithCustomer;

    public function __construct(private readonly WishlistService $wishlist) {}

    public function index(): View
    {
        return view('storefront.account.wishlist.index', [
            'products' => $this->wishlist->products($this->currentCustomer()),
        ]);
    }

    public function toggle(Product $product): RedirectResponse
    {
        $added = $this->wishlist->toggle($this->currentCustomer(), $product);

        return back()->with('status', $added ? __('account.wishlist_added') : __('account.wishlist_removed'));
    }
}
