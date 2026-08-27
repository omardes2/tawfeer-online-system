<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الرقم الذي تعرضه شاشة الطلب هو الرقم الذي يُحفَظ.
 *
 * ## الخلل الذي يحرسه هذا الفحص
 *
 * نموذج الطلب يحسب الإجماليَّ في المتصفّح من خريطة `cityRates`، والخادمُ
 * يحسبه ثانيةً عند الحفظ. ومصدران لرقمٍ واحد يفترقان بصمت: بعد فصل سعر البيع
 * عن التكلفة صارت الخريطة تعطي **٦٥** والخادم يحفظ **٦٣** — فيرى الموظف
 * إجماليًّا ويُقيَّد على الزبون آخر، ويصل المندوبَ مبلغُ تحصيلٍ غير المتّفق عليه.
 *
 * فالفحص هنا ليس «هل الرسوم صحيحة؟» بل **«هل المصدران متّفقان؟»**.
 */
class OrderDeliveryFeeAgreesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private City $city;

    private Area $area;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $gov = Governorate::firstOrCreate(['name' => 'الجنوب'], ['is_active' => true]);
        $this->city = City::create(['governorate_id' => $gov->id, 'name' => 'مناطق جنوب الداخل', 'is_active' => true]);
        $this->area = Area::create(['city_id' => $this->city->id, 'name' => 'عرعرة النقب', 'is_active' => true]);

        $this->product = Product::factory()->create([
            'name' => 'خاتم ذكي', 'retail_price' => 72.5, 'wholesale_price' => 50,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);

        app(InventoryService::class)->openingStock(
            $this->product->defaultVariant, Warehouse::firstOrFail(), 100, 40,
        );
    }

    private function rate(float $cost, ?float $customerFee): DeliveryCityRate
    {
        $provider = DeliveryProvider::firstOrCreate(
            ['code' => 'opost'],
            ['name' => 'Opost', 'driver' => 'opost', 'is_active' => true],
        );

        return DeliveryCityRate::create([
            'delivery_provider_id' => $provider->id,
            'city_id' => $this->city->id,
            'name' => $this->city->name,
            'delivery_fee' => $cost,
            'customer_fee' => $customerFee,
            'currency' => 'ILS',
            'is_active' => true,
        ]);
    }

    /** الرسوم كما تعرضها الشاشة — خريطة `cityRates` التي يقرؤها الـJS. */
    private function shownFee(): float
    {
        return (float) $this->actingAs($this->admin)
            ->get(route('admin.sales.orders.create'))
            ->assertOk()
            ->viewData('cityRates')[$this->city->id];
    }

    /** الرسوم كما يحفظها الخادم بعد تقديم الطلب. */
    private function savedFee(): float
    {
        $this->actingAs($this->admin)->post(route('admin.sales.orders.store'), [
            'customer_name' => 'امير ابو عرار',
            'customer_phone' => '0508724070',
            'shipping_address' => 'عرعرة النقب',
            'city_id' => $this->city->id,
            'area_id' => $this->area->id,
            'items' => [[
                'variant' => $this->product->defaultVariant->uuid,
                'qty' => 2,
                'unit_price' => 72.5,
            ]],
        ]);

        return (float) Order::latest('id')->firstOrFail()->shipping_total;
    }

    /**
     * **المصدران متّفقان حين يُضبط سعر بيع.**
     *
     * هو الفحص الذي كان سيمنع الخلل: الشاشة ٦٥ والخادم ٦٣.
     */
    public function test_the_shown_fee_equals_the_saved_fee_with_a_sale_price(): void
    {
        $this->rate(cost: 63, customerFee: 65);

        $shown = $this->shownFee();
        $saved = $this->savedFee();

        $this->assertEqualsWithDelta(65.0, $shown, 0.01);
        $this->assertEqualsWithDelta($shown, $saved, 0.01);
    }

    /** ومتّفقان حين لا يُضبط — كلاهما التكلفة. */
    public function test_they_agree_without_a_sale_price(): void
    {
        $this->rate(cost: 63, customerFee: null);

        $shown = $this->shownFee();

        $this->assertEqualsWithDelta(63.0, $shown, 0.01);
        $this->assertEqualsWithDelta($shown, $this->savedFee(), 0.01);
    }

    /** وإجمالي الطلب = بضاعة + رسومُ البيع، لا رسومَ التكلفة. */
    public function test_the_order_total_uses_the_sale_price(): void
    {
        $this->rate(cost: 63, customerFee: 65);

        $this->savedFee();

        $order = Order::latest('id')->firstOrFail();

        $this->assertEqualsWithDelta(145.0, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(65.0, (float) $order->shipping_total, 0.01);
        $this->assertEqualsWithDelta(210.0, (float) $order->total, 0.01);
    }
}
