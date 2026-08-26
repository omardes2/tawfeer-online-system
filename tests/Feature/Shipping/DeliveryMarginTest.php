<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Shipping\Services\ShippingCostResolver;
use App\Support\Contracts\Shipping\ShippingQuoteRequest;
use App\Support\Integrations\Shipping\LocalShippingQuoteProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هامش التوصيل — رقمان حيث كان رقمٌ واحد.
 *
 * ## Protected Delivery Integration — Do Not Modify
 *
 * كان `delivery_city_rates.delivery_fee` يُكتب في مكانين معًا: تكلفةً على
 * الشحنة ورسمًا على الطلب. فالهامش صفرٌ **بحكم البنية** لا بحكم التسعير.
 *
 * وصار: `delivery_fee` تكلفةُ الشركة، و`customer_fee` سعرُ البيع. والفراغ
 * يعني «بلا هامش» — فلا تتحرّك مدينةٌ لم يُضبط سعرُها بيدٍ صريحة.
 */
class DeliveryMarginTest extends TestCase
{
    use RefreshDatabase;

    private City $nablus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $gov = Governorate::firstOrCreate(['name' => 'الشمال'], ['is_active' => true]);
        $this->nablus = City::create(['governorate_id' => $gov->id, 'name' => 'نابلس', 'is_active' => true]);
    }

    private function rate(float $cost, ?float $customerFee = null): DeliveryCityRate
    {
        $provider = DeliveryProvider::firstOrCreate(
            ['code' => 'opost'],
            ['name' => 'Opost', 'driver' => 'opost', 'is_active' => true],
        );

        return DeliveryCityRate::create([
            'delivery_provider_id' => $provider->id,
            'city_id' => $this->nablus->id,
            'name' => 'نابلس',
            'delivery_fee' => $cost,
            'customer_fee' => $customerFee,
            'currency' => 'ILS',
            'is_active' => true,
        ]);
    }

    /**
     * مُحلّلٌ فوق مزوّد التسعير المحلّي صراحةً.
     *
     * المربوط افتراضيًّا هو `NullShippingQuoteProvider` (يعتمد على
     * `config('shipping.drivers')`)، فيصمت المُحلّل ويعود بصفر — وفحصُ
     * تمريرِ رقمين عبر مزوّدٍ لا يُرجع شيئًا لا يفحص شيئًا.
     */
    private function resolver(): ShippingCostResolver
    {
        return new ShippingCostResolver(new LocalShippingQuoteProvider);
    }

    // ────────── النموذج ──────────

    /** سعرُ البيع حين يُضبط، والتكلفةُ حين لا يُضبط. */
    public function test_the_customer_fee_falls_back_to_the_cost(): void
    {
        $priced = $this->rate(cost: 17, customerFee: 20);

        $this->assertEqualsWithDelta(17.0, $priced->providerCost(), 0.01);
        $this->assertEqualsWithDelta(20.0, $priced->customerFee(), 0.01);
        $this->assertEqualsWithDelta(3.0, $priced->margin(), 0.01);
    }

    /**
     * **والفراغ يعني «بلا هامش» لا «مجّاني».**
     *
     * إدخال طبقة تسعيرٍ على نظامٍ يبيع فعلًا يجب ألّا يحرّك سعر مدينةٍ واحدة
     * حتى يُضبط سعرُها بيدٍ صريحة. والصفر كان سيُلغي الرسوم عند أوّل نشر.
     */
    public function test_a_blank_sale_price_changes_nothing(): void
    {
        $plain = $this->rate(cost: 17);

        $this->assertEqualsWithDelta(17.0, $plain->customerFee(), 0.01);
        $this->assertEqualsWithDelta(0.0, $plain->margin(), 0.01);
    }

    /** وسعرُ بيعٍ صفرٌ صريح يعني توصيلًا مجّانيًّا — قرارٌ آخر تمامًا. */
    public function test_an_explicit_zero_means_free_delivery(): void
    {
        $free = $this->rate(cost: 17, customerFee: 0);

        $this->assertEqualsWithDelta(0.0, $free->customerFee(), 0.01);
        $this->assertEqualsWithDelta(-17.0, $free->margin(), 0.01);
    }

    // ────────── العرض والمُحلّل ──────────

    /** عرضُ السعر المحلّي يحمل الرقمين. */
    public function test_the_local_quote_carries_both_numbers(): void
    {
        $this->rate(cost: 17, customerFee: 20);

        $quote = app(LocalShippingQuoteProvider::class)
            ->quote(new ShippingQuoteRequest(cityId: $this->nablus->id));

        $this->assertNotNull($quote);
        $this->assertEqualsWithDelta(17.0, $quote->cost, 0.01);
        $this->assertEqualsWithDelta(20.0, $quote->customerFee(), 0.01);
    }

    /** والمُحلّل يمرّرهما بلا طيّ. */
    public function test_the_resolver_keeps_them_apart(): void
    {
        $this->rate(cost: 17, customerFee: 20);

        $result = $this->resolver()->resolve(['city_id' => $this->nablus->id]);

        $this->assertEqualsWithDelta(17.0, $result->cost, 0.01);
        $this->assertEqualsWithDelta(20.0, $result->customerFee(), 0.01);
        $this->assertEqualsWithDelta(3.0, $result->margin(), 0.01);
    }

    /** ومدينةٌ بلا سعر بيعٍ يعود رقماها متساويين. */
    public function test_a_city_without_a_sale_price_resolves_to_one_number(): void
    {
        $this->rate(cost: 17);

        $result = $this->resolver()->resolve(['city_id' => $this->nablus->id]);

        $this->assertEqualsWithDelta(17.0, $result->cost, 0.01);
        $this->assertEqualsWithDelta(17.0, $result->customerFee(), 0.01);
        $this->assertEqualsWithDelta(0.0, $result->margin(), 0.01);
    }

    // ────────── الشاشة ──────────

    /** شاشة الأسعار تعرض العمودين والهامش. */
    public function test_the_rates_screen_shows_the_margin(): void
    {
        $this->rate(cost: 17, customerFee: 20);

        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.shipping.delivery_rates.index'))
            ->assertOk()
            ->assertSee('تكلفة الشركة')
            ->assertSee('سعر البيع للزبون')
            ->assertSee('الهامش');
    }

    /** ويُحفَظ سعر البيع من الشاشة. */
    public function test_the_sale_price_can_be_saved(): void
    {
        $rate = $this->rate(cost: 17);
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.shipping.delivery_rates.update'), [
            'rates' => [$rate->id => ['delivery_fee' => 17, 'customer_fee' => 20, 'is_active' => 1]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(20.0, $rate->fresh()->customerFee(), 0.01);
        $this->assertEqualsWithDelta(3.0, $rate->fresh()->margin(), 0.01);
    }

    /** وإفراغُ الحقل يُعيد المدينة إلى تكلفتها. */
    public function test_clearing_it_returns_the_city_to_its_cost(): void
    {
        $rate = $this->rate(cost: 17, customerFee: 20);
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->put(route('admin.shipping.delivery_rates.update'), [
            'rates' => [$rate->id => ['delivery_fee' => 17, 'customer_fee' => '', 'is_active' => 1]],
        ])->assertRedirect();

        $this->assertNull($rate->fresh()->customer_fee);
        $this->assertEqualsWithDelta(17.0, $rate->fresh()->customerFee(), 0.01);
    }
}
