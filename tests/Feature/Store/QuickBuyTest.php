<?php

namespace Tests\Feature\Store;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «شراء الآن» في صفحة المنتج.
 *
 * ⚠️ Protected Delivery Integration — Do Not Modify.
 *
 * الزرّ **شكلٌ آخر حول مسار الإتمام القائم لا مسار جديد**: لا نقطة API مستحدثة،
 * ولا حساب رسوم في الواجهة، ولا عقد مختلف. وأكثر ما تحرسه هذه الاختبارات ليس
 * ظهور الزرّ بل بقاء ذلك المسار كما هو.
 */
class QuickBuyTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private City $city;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->product = Product::factory()->create(['name' => 'جهاز تعطير']);
        $this->product->update(['is_active' => true]);

        // بلا رصيد لا يُعرض زرّ شراء أصلًا — الصفحة تقول «غير متوفّر».
        InventoryStock::create([
            'variant_id' => $this->product->defaultVariant->id,
            'warehouse_id' => Warehouse::where('is_default', true)->firstOrFail()->id,
            'on_hand' => 10,
            'reserved' => 0,
        ]);

        // مدينة مسعّرة من جغرافيا البذرة لا مخترعة: القائمتان تُبنيان من المدن
        // التي لها تسعيرة مفعّلة، وبلا واحدةٍ منها يمرّ الاختبار بلا شيء يفحصه.
        $this->city = City::where('is_active', true)->orderBy('id')->firstOrFail();
        $this->area = Area::firstOrCreate(
            ['city_id' => $this->city->id, 'name' => 'منطقة الاختبار'],
            ['is_active' => true, 'sort_order' => 1],
        );
        DeliveryCityRate::create([
            'city_id' => $this->city->id,
            'name' => $this->city->name,
            'delivery_fee' => 20,
            'is_active' => true,
        ]);
    }

    private function page(): string
    {
        return $this->get(route('storefront.product', $this->product->slug))->assertOk()->getContent();
    }

    public function test_the_buy_now_button_appears_on_the_product_page(): void
    {
        $this->assertStringContainsString(__('storefront.buy_now'), $this->page());
    }

    /** ويحمل نفس مفاتيح نموذج الإتمام حرفيًّا — لا عقد بديل. */
    public function test_the_drawer_uses_the_checkout_field_keys(): void
    {
        $html = $this->page();

        foreach ([
            'form.customer_name', 'form.customer_phone', 'form.shipping_address',
            'form.city_id', 'form.area_id', 'form.payment_method_code',
        ] as $key) {
            $this->assertStringContainsString($key, $html, "مفتاح الإتمام {$key} غائب عن «شراء الآن».");
        }

        // والدفع عند الاستلام بالقيمة نفسها.
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*value="cod"/', $html);
    }

    /**
     * ولا تُحسب رسوم التوصيل في الواجهة.
     *
     * الرسوم تأتي من استجابة الـPATCH وتُعرض كما وردت. أي حساب هنا يجعل الرقم
     * المعروض يفترق عمّا يُسجَّل في الطلب.
     */
    public function test_no_delivery_fee_is_computed_in_the_browser(): void
    {
        $html = $this->page();

        // تُعرَض كما وردت من الخلفية لا غير.
        $this->assertStringContainsString('totals.delivery_fee', $html);
        // ولا قيمة رسومٍ ثابتة ولا «توصيل مجاني» تجريبي.
        $this->assertStringNotContainsString('delivery_fee =', $html);
        $this->assertStringNotContainsString('deliveryFee =', $html);
    }

    /** والمدن هي مدن الإتمام نفسها: المسعّرة المفعّلة فقط. */
    public function test_it_offers_the_same_cities_as_the_checkout_page(): void
    {
        $product = $this->page();
        $checkout = $this->get(route('storefront.checkout'))->assertOk()->getContent();

        $this->assertStringContainsString($this->city->name, $product);
        $this->assertStringContainsString($this->city->name, $checkout);
        // والمنطقة تتبع مدينتها في الاثنين.
        $this->assertStringContainsString($this->area->name, $product);
        $this->assertStringContainsString($this->area->name, $checkout);
    }

    /** ومدينة بلا تسعيرة لا تُعرض في أيٍّ من المسارين. */
    public function test_an_unrated_city_is_offered_nowhere(): void
    {
        $unrated = City::where('is_active', true)->where('id', '!=', $this->city->id)
            ->whereNotIn('id', DeliveryCityRate::pluck('city_id'))->firstOrFail();
        $orphanArea = Area::firstOrCreate(
            ['city_id' => $unrated->id, 'name' => 'منطقة بلا تسعيرة'],
            ['is_active' => true, 'sort_order' => 1],
        );

        $product = $this->page();
        $checkout = $this->get(route('storefront.checkout'))->assertOk()->getContent();

        // المنطقة هي الشاهد: اسم المدينة قد يرد في مكانٍ آخر من الصفحة.
        $this->assertStringNotContainsString($orphanArea->name, $product);
        $this->assertStringNotContainsString($orphanArea->name, $checkout);
    }

    /**
     * صفحة الإتمام الكاملة لم تُمسّ.
     *
     * هذا هو الحارس الحقيقي: «شراء الآن» أُضيف حولها، فإن كسر شيئًا منها ظهر هنا.
     */
    public function test_the_full_checkout_page_is_untouched(): void
    {
        $html = $this->get(route('storefront.checkout'))->assertOk()->getContent();

        foreach ([
            'storefrontCheckout()', 'c-name', 'c-phone', 'c-city', 'c-area', 'c-address',
            // المسار يُبنى من ثابت `API` فلا يرد حرفيًّا كاملًا.
            "const API = '/api/v1/store'", '${API}/checkout', 'place()', 'pickCity()',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html, "جزءٌ محميّ من صفحة الإتمام تغيّر: {$needle}");
        }
    }

    /** ولا مسار إتمامٍ جديد في الخلفية — الأربعة القائمة لا غير. */
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
}
