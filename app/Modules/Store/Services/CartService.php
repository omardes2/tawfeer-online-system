<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Store\Models\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق سلة التسوّق (ADR-031). السعر لقطة من الكتالوج؛ لا حجز مخزون (يتم عند تأكيد الطلب — ADR-009).
 */
class CartService
{
    /** سلة المستخدم النشطة (تُنشأ عند الحاجة). */
    public function forUser(User $user): Cart
    {
        $customerId = Customer::where('user_id', $user->id)->value('id');

        return Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['customer_id' => $customerId, 'branch_id' => $user->branch_id],
        );
    }

    public function addItem(Cart $cart, ProductVariant $variant, float $qty): Cart
    {
        $this->assertPositive($qty);
        $this->assertSellable($variant);

        return DB::transaction(function () use ($cart, $variant, $qty) {
            $existing = $cart->items()->where('variant_id', $variant->id)->first();
            $newQty = ($existing ? (float) $existing->qty : 0) + $qty;

            $this->assertAvailable($variant, $newQty);

            $cart->items()->updateOrCreate(
                ['variant_id' => $variant->id],
                ['qty' => $newQty, 'unit_price' => $this->sellingPrice($variant)],
            );

            return $cart->fresh('items');
        });
    }

    /** ضبط كمية بند مطلقة (0 = حذف). */
    public function setItem(Cart $cart, ProductVariant $variant, float $qty): Cart
    {
        if ($qty <= 0) {
            return $this->removeItem($cart, $variant);
        }
        $this->assertSellable($variant);
        $this->assertAvailable($variant, $qty);

        $cart->items()->updateOrCreate(
            ['variant_id' => $variant->id],
            ['qty' => $qty, 'unit_price' => $this->sellingPrice($variant)],
        );

        return $cart->fresh('items');
    }

    public function removeItem(Cart $cart, ProductVariant $variant): Cart
    {
        $cart->items()->where('variant_id', $variant->id)->delete();

        return $cart->fresh('items');
    }

    public function clear(Cart $cart): Cart
    {
        $cart->items()->delete();

        return $cart->fresh('items');
    }

    /** سعر البيع الفعّال من الكتالوج (عرض ترويجي إن وُجد، وإلا التجزئة). لا محرّك تسعير (مؤجّل). */
    public function sellingPrice(ProductVariant $variant): float
    {
        $promo = (float) $variant->promo_price;

        return $promo > 0 ? $promo : (float) $variant->retail_price;
    }

    /** المتاح = Σ(on_hand − reserved) عبر المستودعات. */
    public function availableQty(ProductVariant $variant): float
    {
        $stocks = InventoryStock::where('variant_id', $variant->id)->get();

        return (float) $stocks->sum(fn ($s) => (float) $s->on_hand - (float) $s->reserved);
    }

    /** التحقّق أن المتغيّر قابل للبيع ومتوفّر بالكمية — يُعاد استخدامه عند إتمام الشراء (3.2). */
    public function assertPurchasable(ProductVariant $variant, float $qty): void
    {
        $this->assertSellable($variant);
        $this->assertAvailable($variant, $qty);
    }

    private function assertSellable(ProductVariant $variant): void
    {
        $product = $variant->product;
        if (! $product || ! $product->is_active || $product->visibility !== 'visible') {
            throw ValidationException::withMessages(['variant' => __('المنتج غير متاح للبيع.')]);
        }
    }

    private function assertAvailable(ProductVariant $variant, float $qty): void
    {
        if ($qty > $this->availableQty($variant) + 1e-9) {
            throw ValidationException::withMessages(['qty' => __('الكمية المطلوبة تتجاوز المتاح في المخزون.')]);
        }
    }

    private function assertPositive(float $qty): void
    {
        if ($qty <= 0) {
            throw ValidationException::withMessages(['qty' => __('الكمية يجب أن تكون أكبر من صفر.')]);
        }
    }
}
