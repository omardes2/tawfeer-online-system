<?php

namespace App\Modules\Store\Services;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\OfferPricing;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Store\Models\Cart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * منطق سلة التسوّق (ADR-031). السعر لقطة من الكتالوج؛ لا حجز مخزون (يتم عند تأكيد الطلب — ADR-009).
 */
class CartService
{
    public function __construct(private readonly OfferPricing $offers) {}

    /** سلة المستخدم النشطة (تُنشأ عند الحاجة). */
    public function forUser(User $user): Cart
    {
        $customerId = Customer::where('user_id', $user->id)->value('id');

        return Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['customer_id' => $customerId, 'branch_id' => $user->branch_id],
        );
    }

    /** سلة الضيف النشطة عبر رمز الجلسة (Phase 3.2 — تُنشأ عند الحاجة، فرع افتراضي). */
    public function forGuest(string $sessionToken): Cart
    {
        return Cart::firstOrCreate(
            ['session_token' => $sessionToken, 'status' => 'active'],
            ['branch_id' => Branch::default()?->id],
        );
    }

    /**
     * حلّ السلة النشطة للهوية الحالية (مستخدم مُصادَق أو ضيف برمز جلسة).
     * تضمن طبقة الهوية (store.identity) وجود إحداهما؛ تُعيد null احتياطًا فقط.
     */
    public function resolveActive(?User $user, ?string $guestToken): ?Cart
    {
        if ($user) {
            return $this->forUser($user);
        }
        if (is_string($guestToken) && $guestToken !== '') {
            return $this->forGuest($guestToken);
        }

        return null;
    }

    /**
     * دمج سلة الضيف (برمز الجلسة) في سلة المستخدم عند تسجيل الدخول/التسجيل (Phase 3.4).
     * تُنقل البنود بالتراكم ثم تُعلَّم سلة الضيف «merged». يُعاد استخدام تسعير الكتالوج.
     */
    public function mergeGuestIntoUser(User $user, ?string $guestToken): Cart
    {
        $userCart = $this->forUser($user);
        if (! is_string($guestToken) || $guestToken === '') {
            return $userCart;
        }

        $guestCart = Cart::where('session_token', $guestToken)
            ->whereNull('user_id')->where('status', 'active')->first();

        if (! $guestCart || $guestCart->id === $userCart->id) {
            return $userCart;
        }

        return DB::transaction(function () use ($guestCart, $userCart) {
            $guestCart->loadMissing('items');
            foreach ($guestCart->items as $item) {
                $existing = $userCart->items()->where('variant_id', $item->variant_id)->first();
                $userCart->items()->updateOrCreate(
                    ['variant_id' => $item->variant_id],
                    [
                        'qty' => ($existing ? (float) $existing->qty : 0) + (float) $item->qty,
                        'unit_price' => $item->unit_price,
                    ],
                );
            }
            $guestCart->update(['status' => 'merged']);

            return $userCart->fresh('items');
        });
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

            $this->applyOffers($cart, $variant);

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

        $this->applyOffers($cart, $variant);

        return $cart->fresh('items');
    }

    public function removeItem(Cart $cart, ProductVariant $variant): Cart
    {
        $cart->items()->where('variant_id', $variant->id)->delete();

        // بعد الحذف قد تنزل الكمّية تحت العرض، فيعود الباقي إلى سعره العادي.
        $this->applyOffers($cart, $variant);

        return $cart->fresh('items');
    }

    public function clear(Cart $cart): Cart
    {
        $cart->items()->delete();

        return $cart->fresh('items');
    }

    /**
     * إعادة تسعير بنود صنفٍ بعد تغيّر كمّيته — عروض الكمّية.
     *
     * ⚠️ Protected Delivery Integration — Do Not Modify.
     *
     * العرض يُطبَّق **هنا وحده**، في طبقة تسعير السلة. ومسار الإتمام لا يُمسّ:
     * هو ينسخ `unit_price` من بند السلة إلى بند الطلب كما كان يفعل دائمًا،
     * ورسوم التوصيل تُحسب على مجموع السلة كما كانت. فلا نقطة API مستحدثة ولا
     * عقد مختلف ولا حساب رسومٍ في الواجهة.
     *
     * والكمّية تُجمع عبر **متغيّرات الصنف كلّها**: خمس قطعٍ بمقاساتٍ مختلفة
     * عرضٌ واحد. ويُعاد الحساب في كل تغيير — إضافةً كان أو حذفًا — فنزولُ
     * الكمّية تحت العرض يُعيد الباقي إلى سعره العادي.
     */
    private function applyOffers(Cart $cart, ProductVariant $variant): void
    {
        $product = $variant->product;

        if (! $product) {
            return;
        }

        $offers = $product->activeOffers()->get();

        if ($offers->isEmpty()) {
            return;
        }

        $variantIds = $product->variants()->pluck('id');
        $lines = $cart->items()->whereIn('variant_id', $variantIds)->with('variant')->get();
        $qty = (float) $lines->sum('qty');

        foreach ($lines as $line) {
            $regular = $line->variant ? $this->sellingPrice($line->variant) : (float) $line->unit_price;
            $price = $this->offers->unitPrice($offers, $qty, $regular);

            if (abs((float) $line->unit_price - $price) >= 0.001) {
                $line->update(['unit_price' => $price]);
            }
        }
    }

    /** سعر البيع الفعّال من الكتالوج (عرض ترويجي إن وُجد، وإلا التجزئة). لا محرّك تسعير (مؤجّل). */
    public function sellingPrice(ProductVariant $variant): float
    {
        $promo = (float) $variant->promo_price;

        return $promo > 0 ? $promo : (float) $variant->retail_price;
    }

    /**
     * المتاح = Σ(on_hand − reserved) عبر المستودعات.
     * مدرك للتحميل المُسبق: يستخدم علاقة inventoryStocks المحمّلة إن وُجدت (يتفادى N+1
     * في بطاقات المتجر)، وإلا يستعلم — سلوك متطابق.
     */
    public function availableQty(ProductVariant $variant): float
    {
        $stocks = $variant->relationLoaded('inventoryStocks')
            ? $variant->inventoryStocks
            : InventoryStock::where('variant_id', $variant->id)->get();

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
