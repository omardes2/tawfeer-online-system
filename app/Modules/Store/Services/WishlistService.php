<?php

namespace App\Modules\Store\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Store\Models\WishlistItem;
use Illuminate\Support\Collection;

/**
 * قائمة المفضّلة (Phase 3.4 / ADR-035). كل المنطق هنا — لا تكرار في الواجهة.
 * جاهزية «ذكاء قائمة الأمنيات» مستقبلًا عبر الاستعلامات/الأحداث (بلا منطق نمو).
 */
class WishlistService
{
    /** تبديل حالة المنتج في المفضّلة؛ يُعيد true إن أُضيف، false إن أُزيل. */
    public function toggle(Customer $customer, Product $product): bool
    {
        $existing = WishlistItem::where('customer_id', $customer->id)
            ->where('product_id', $product->id)->first();

        if ($existing) {
            $existing->delete();

            return false;
        }

        WishlistItem::create(['customer_id' => $customer->id, 'product_id' => $product->id]);

        return true;
    }

    public function remove(Customer $customer, Product $product): void
    {
        WishlistItem::where('customer_id', $customer->id)
            ->where('product_id', $product->id)->delete();
    }

    public function has(Customer $customer, Product $product): bool
    {
        return WishlistItem::where('customer_id', $customer->id)
            ->where('product_id', $product->id)->exists();
    }

    /** منتجات المفضّلة المعروضة/الفعّالة (للعرض في المتجر). */
    public function products(Customer $customer): Collection
    {
        return Product::query()->active()->visible()
            ->whereIn('id', $customer->wishlistItems()->pluck('product_id'))
            ->with(['primaryImage', 'defaultVariant', 'brand'])
            ->latest('id')->get();
    }
}
