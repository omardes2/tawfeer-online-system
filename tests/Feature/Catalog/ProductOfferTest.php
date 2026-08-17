<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOffer;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Store\Models\Cart;
use App\Modules\Store\Services\CartService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عروض الكمّية.
 *
 * أخطر ما تحرسه هذه الاختبارات ليس أن العرض يعمل، بل **أن مسار الإتمام لم
 * يتغيّر**: العرض يُطبَّق في تسعير السلة وحده، والإتمام ينسخ السعر كما كان
 * ينسخه دائمًا. أيّ تسعيرٍ يتسرّب إلى الإتمام يجعل ما يدفعه الزبون يفترق عمّا
 * رآه — وهو خطأٌ لا يظهر إلّا حين يشتكي.
 */
class ProductOfferTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $small;

    private ProductVariant $large;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->product = Product::factory()->create(['name' => 'مشدّ']);
        // مصنع المنتج يُنشئه غير مفعّل، ولا يُباع غير المفعّل.
        $this->product->update(['is_active' => true]);
        $this->product->defaultVariant->update(['retail_price' => 40, 'average_cost' => 15, 'is_active' => true]);

        $this->small = $this->product->defaultVariant->refresh();
        $this->large = ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'retail_price' => 40,
            'average_cost' => 15,
            'is_active' => true,
        ]);

        foreach ([$this->small, $this->large] as $variant) {
            InventoryStock::create([
                'variant_id' => $variant->id,
                'warehouse_id' => Warehouse::where('is_default', true)->firstOrFail()->id,
                'on_hand' => 50,
                'reserved' => 0,
            ]);
        }
    }

    private function offer(int $qty, float $total): ProductOffer
    {
        return $this->product->offers()->create([
            'min_qty' => $qty, 'total_price' => $total, 'is_active' => true,
        ]);
    }

    private function cart(): Cart
    {
        return app(CartService::class)->forGuest('guest-'.uniqid());
    }

    private function service(): CartService
    {
        return app(CartService::class);
    }

    // ────────── التسعير ──────────

    /** بلوغ كمّية العرض يُسعّر القطع كلَّها بسعره. */
    public function test_reaching_the_offer_quantity_prices_every_unit(): void
    {
        $this->offer(5, 100);   // 20 للقطعة بدل 40
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 5);

        $this->assertEqualsWithDelta(20.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /** ودون الكمّية يبقى السعر العادي. */
    public function test_below_the_quantity_the_regular_price_stands(): void
    {
        $this->offer(5, 100);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 4);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /**
     * والكمّية تُجمع عبر متغيّرات الصنف — وهذا هو المقصد.
     *
     * ثلاثُ قطعٍ بمقاسٍ واثنتان بآخر عرضٌ واحد. ولو جُمعت على المتغيّر لسقط
     * العرض عن أكثر الحالات شيوعًا بلا أن يفهم أحدٌ لماذا.
     */
    public function test_the_quantity_is_summed_across_the_variants_of_the_product(): void
    {
        $this->offer(5, 100);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 3);
        $this->service()->addItem($cart, $this->large, 2);

        foreach ($cart->fresh('items')->items as $item) {
            $this->assertEqualsWithDelta(20.0, (float) $item->unit_price, 0.01);
        }
    }

    /** ويُختار أعلى عرضٍ تبلغه الكمّية لا أوّلها. */
    public function test_the_highest_reached_offer_wins(): void
    {
        $this->offer(2, 70);    // 35 للقطعة
        $this->offer(5, 100);   // 20 للقطعة
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 5);

        $this->assertEqualsWithDelta(20.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /** ومن اشترى أكثر من كمّية العرض يأخذ سعره على القطع كلّها. */
    public function test_buying_more_than_the_offer_spreads_its_price(): void
    {
        $this->offer(5, 100);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 6);

        // مئةٌ على ستّ قطع ⇒ 16.67 للقطعة، لا 20 ولا واحدةٌ مجّانية.
        $this->assertEqualsWithDelta(16.67, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /**
     * ونزول الكمّية يُعيد السعر العادي.
     *
     * بلا هذا يبقى سعر العرض على قطعةٍ واحدة بعد أن يحذف الزبون الباقي —
     * وهي خسارةٌ صامتة لا يراها أحد حتى تُجمع آخر الشهر.
     */
    public function test_dropping_below_the_quantity_restores_the_regular_price(): void
    {
        $this->offer(5, 100);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 5);
        $this->service()->setItem($cart, $this->small, 2);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /** وحذفُ مقاسٍ يُعيد تسعير الباقي. */
    public function test_removing_a_variant_reprices_what_remains(): void
    {
        $this->offer(5, 100);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 3);
        $this->service()->addItem($cart, $this->large, 2);
        $this->service()->removeItem($cart, $this->large);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /**
     * والعرض لا يرفع السعر أبدًا.
     *
     * صاحب المتجر قد يترك عرضًا قديمًا على صنفٍ خُفِّض سعره، والزبون لا يجوز
     * أن يدفع أكثر لأنه اشترى أكثر.
     */
    public function test_an_offer_never_raises_the_price(): void
    {
        $this->offer(2, 200);   // 100 للقطعة، والعادي 40
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 2);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /** وعرضٌ معطَّل لا يُسعِّر شيئًا. */
    public function test_an_inactive_offer_is_ignored(): void
    {
        $this->offer(5, 100)->update(['is_active' => false]);
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 5);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    /** وصنفٌ بلا عروض لا يتغيّر تسعيره إطلاقًا. */
    public function test_a_product_without_offers_is_untouched(): void
    {
        $cart = $this->cart();

        $this->service()->addItem($cart, $this->small, 5);

        $this->assertEqualsWithDelta(40.0, (float) $cart->fresh('items')->items->first()->unit_price, 0.01);
    }

    // ────────── الإدارة ──────────

    /** العرض يُضاف من صفحة تعديل المنتج. */
    public function test_an_offer_is_created_from_the_product_page(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.offers.store', $this->product), [
                'min_qty' => 5, 'total_price' => 100, 'is_active' => 1,
            ])->assertRedirect();

        $this->assertSame(1, $this->product->offers()->count());
        $this->assertEqualsWithDelta(20.0, $this->product->offers()->first()->unitPrice(), 0.01);
    }

    /** ولا يُقبل عرضان بالكمّية نفسها — وإلّا صار السعر رهنَ ترتيبٍ عشوائي. */
    public function test_two_offers_with_the_same_quantity_are_refused(): void
    {
        $this->offer(5, 100);

        $this->actingAs($this->admin())
            ->post(route('admin.products.offers.store', $this->product), [
                'min_qty' => 5, 'total_price' => 90,
            ])->assertSessionHasErrors('min_qty');
    }

    /** ولا عرضَ على قطعةٍ واحدة: ذاك سعرٌ ترويجي لا عرض. */
    public function test_a_single_unit_offer_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.products.offers.store', $this->product), [
                'min_qty' => 1, 'total_price' => 30,
            ])->assertSessionHasErrors('min_qty');
    }

    /** وعرضُ صنفٍ آخر لا يُعدَّل من صفحة هذا الصنف. */
    public function test_an_offer_of_another_product_cannot_be_edited_here(): void
    {
        $other = Product::factory()->create();
        $foreign = $other->offers()->create(['min_qty' => 3, 'total_price' => 50, 'is_active' => true]);

        $this->actingAs($this->admin())
            ->put(route('admin.products.offers.update', [$this->product, $foreign]), [
                'min_qty' => 3, 'total_price' => 10,
            ])->assertNotFound();

        $this->assertEqualsWithDelta(50.0, (float) $foreign->refresh()->total_price, 0.01);
    }

    // ────────── الحارس: مسار الإتمام لم يُمسّ ──────────

    /** لا نقطة إتمامٍ جديدة. */
    public function test_no_new_checkout_endpoint_was_added(): void
    {
        $checkout = collect(app('router')->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'store/checkout'))
            ->map(fn ($r) => $r->methods()[0].' '.$r->uri())
            ->values()->sort()->values()->all();

        $this->assertSame([
            'GET api/v1/store/checkout/{session}',
            'POST api/v1/store/checkout',
            'POST api/v1/store/checkout/{session}/place',
            'PUT api/v1/store/checkout/{session}',
        ], $checkout);
    }

    /** وصفحة الإتمام الكاملة كما هي. */
    public function test_the_full_checkout_page_is_untouched(): void
    {
        $html = $this->get(route('storefront.checkout'))->assertOk()->getContent();

        foreach ([
            'storefrontCheckout()', 'c-name', 'c-phone', 'c-city', 'c-area', 'c-address',
            "const API = '/api/v1/store'", '${API}/checkout', 'place()', 'pickCity()',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "جزءٌ محميّ من صفحة الإتمام تغيّر: {$needle}");
        }
    }

    /** وبطاقة العروض تظهر في صفحة المنتج بالسعر الأصلي مشطوبًا. */
    public function test_the_offer_card_shows_on_the_product_page(): void
    {
        $this->offer(5, 100);
        $this->product->update(['is_active' => true]);

        $html = $this->get(route('storefront.product', $this->product->slug))->assertOk()->getContent();

        $this->assertStringContainsString(__('storefront.offers_title'), $html);
        // السعر الأصلي يُمرَّر للمقارنة، فيُشطَب بجانب سعر العرض.
        $this->assertStringContainsString('sf-price-old', $html);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }
}
