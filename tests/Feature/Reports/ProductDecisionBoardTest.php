<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Reporting\Services\ProductDecisionService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * لوحة قرار الصنف.
 *
 * ما تحرسه هذه الاختبارات هو جوهر الفائدة: **صنفٌ يتصدّر المبيعات وهو خاسر**
 * يجب أن يظهر خاسرًا — لأن الإعلان والتوصيل يأكلان ربحه، وتقرير «المبيعات حسب
 * المنتج» يتوقّف قبلهما فلا يراه أحد.
 *
 * والشقّ الثاني: **متى ينفد**. البضاعة تأتي بالكونتينر في شهور، فتحذيرٌ بعد
 * النفاد لا قيمة له — والقيمة في «يكفي 18 يومًا ومهلتك 90».
 */
class ProductDecisionBoardTest extends TestCase
{
    use RefreshDatabase;

    private ProductDecisionService $service;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $this->service = app(ProductDecisionService::class);
    }

    private function product(string $name, float $cost = 40): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $product->defaultVariant->update(['average_cost' => $cost]);

        return $product;
    }

    private function sell(Product $product, float $price, int $qty = 1, array $attrs = []): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ], [['variant_id' => $product->defaultVariant->id, 'qty' => $qty, 'unit_price' => $price]], 2026);

        $order->update($attrs + ['status' => 'delivered']);

        return $order->refresh();
    }

    private function spendOnAds(Product $product, float $usd): void
    {
        $channel = AdChannel::first() ?? AdChannel::create(['name' => 'توفير', 'is_active' => true]);

        AdDailySpend::create([
            'spend_date' => today(),
            'ad_channel_id' => $channel->id,
            'product_id' => $product->id,
            'amount_usd' => $usd,
            'fx_rate' => 3.5,
            'conversations' => 10,
        ]);
    }

    private function stock(Product $product, float $onHand): void
    {
        InventoryStock::updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'variant_id' => $product->defaultVariant->id],
            ['on_hand' => $onHand, 'reserved' => 0],
        );
    }

    private function row(Product $product, string $preset = 'month'): ?array
    {
        return $this->service->board(DateRange::resolve($preset))->firstWhere('product_id', $product->id);
    }

    // ────────── الربح الحقيقي ──────────

    /** صافي الربح يخصم الإعلان — وهذا ما لا يفعله تقرير المبيعات. */
    public function test_net_profit_subtracts_ad_spend(): void
    {
        $p = $this->product('جهاز تعطير', cost: 40);
        $this->sell($p, 100);          // بيع 100، تكلفة 40 ⇒ ربح قبل الإعلان 60
        $this->spendOnAds($p, 10);     // 10 × 3.5 = 35 شيكل

        $row = $this->row($p);

        $this->assertEqualsWithDelta(100.0, $row['sales'], 0.01);
        $this->assertEqualsWithDelta(35.0, $row['ad_spend'], 0.01);
        $this->assertEqualsWithDelta(25.0, $row['net_profit'], 0.01); // 100 − 40 − 35
    }

    /**
     * وصنفٌ رابحٌ ظاهريًّا يظهر **خاسرًا** حين يبتلع إعلانُه ربحه.
     *
     * هذه هي الفائدة كلّها: بلا هذه اللوحة يبدو الصنف ناجحًا في تقرير المبيعات
     * (ربح 60) بينما هو يخسر فعلًا.
     */
    public function test_a_top_seller_can_show_as_losing(): void
    {
        $p = $this->product('مشد', cost: 40);
        $this->sell($p, 100);
        $this->spendOnAds($p, 30); // 105 شيكل ⇒ 100 − 40 − 105 = −45

        $row = $this->row($p);

        $this->assertLessThan(0, $row['net_profit']);
        $this->assertSame('losing', $row['verdict']['key']);
    }

    /** شحنةٌ بتكلفةٍ محدّدة على طلب. */
    private function ship(Order $order, float $cost): void
    {
        Shipment::create([
            'number' => 'SHP-PD-'.$order->id,
            'order_id' => $order->id,
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'delivered',
            'kind' => 'outbound',
            'recipient_name' => 'زبون',
            'recipient_phone' => '0599000000',
            'shipping_cost' => $cost,
        ]);
    }

    /**
     * التوصيل يُخصَم **صافيًا**: المدفوع للشركة ناقص المُحصَّل من الزبون.
     *
     * كان المدفوع يُخصم وحده بلا إيراده المقابل، فيظهر نصف الربح تقريبًا —
     * و«الحكم» على الصنف مبنيٌّ على هذا الرقم، فيُوقَف صنفٌ رابح.
     */
    public function test_delivery_is_subtracted_net_of_what_the_customer_paid(): void
    {
        $p = $this->product('مبخرة', cost: 40);
        $order = $this->sell($p, 100, attrs: ['shipping_total' => 15]);
        $this->ship($order, cost: 20);

        $row = $this->row($p);

        $this->assertEqualsWithDelta(20.0, $row['delivery_cost'], 0.01);
        $this->assertEqualsWithDelta(15.0, $row['delivery_revenue'], 0.01);
        $this->assertEqualsWithDelta(5.0, $row['delivery_net'], 0.01);       // 20 − 15
        $this->assertEqualsWithDelta(55.0, $row['net_profit'], 0.01);        // 100 − 40 − 5
    }

    /** ولو لم يدفع الزبون شيئًا، خُصمت التكلفة كاملةً كما كانت. */
    public function test_free_delivery_still_costs_the_full_amount(): void
    {
        $p = $this->product('مبخرة', cost: 40);
        $order = $this->sell($p, 100);
        $this->ship($order, cost: 20);

        $row = $this->row($p);

        $this->assertEqualsWithDelta(20.0, $row['delivery_net'], 0.01);
        $this->assertEqualsWithDelta(40.0, $row['net_profit'], 0.01);        // 100 − 40 − 20
    }

    /** ورسومٌ تفوق التكلفة تُنتج صافيًا سالبًا — ربحٌ من التوصيل يزيد الربح. */
    public function test_charging_more_than_the_cost_adds_to_profit(): void
    {
        $p = $this->product('مبخرة', cost: 40);
        $order = $this->sell($p, 100, attrs: ['shipping_total' => 30]);
        $this->ship($order, cost: 20);

        $row = $this->row($p);

        $this->assertEqualsWithDelta(-10.0, $row['delivery_net'], 0.01);
        $this->assertEqualsWithDelta(70.0, $row['net_profit'], 0.01);        // 100 − 40 + 10
    }

    /** والطرفان يُوزَّعان بنفس النسبة حين يحمل الطلب صنفين. */
    public function test_both_sides_split_by_the_same_share(): void
    {
        $cheap = $this->product('رخيص', cost: 0);
        $dear = $this->product('غالٍ', cost: 0);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ], [
            ['variant_id' => $cheap->defaultVariant->id, 'qty' => 1, 'unit_price' => 25],
            ['variant_id' => $dear->defaultVariant->id, 'qty' => 1, 'unit_price' => 75],
        ], 2026);

        $order->update(['status' => 'delivered', 'shipping_total' => 20]);
        $this->ship($order->refresh(), cost: 40);

        // الحصص ٢٥٪ و٧٥٪ من ١٠٠.
        $this->assertEqualsWithDelta(10.0, $this->row($cheap)['delivery_cost'], 0.01);
        $this->assertEqualsWithDelta(5.0, $this->row($cheap)['delivery_revenue'], 0.01);
        $this->assertEqualsWithDelta(30.0, $this->row($dear)['delivery_cost'], 0.01);
        $this->assertEqualsWithDelta(15.0, $this->row($dear)['delivery_revenue'], 0.01);
    }

    // ────────── التغطية والشراء ──────────

    /** أيام التغطية = المتوفّر ÷ متوسط البيع اليومي. */
    public function test_days_of_cover_is_stock_over_daily_velocity(): void
    {
        $p = $this->product('صنف سريع');
        // 30 قطعة في فترةٍ من يومٍ واحد ⇒ 30 قطعة يوميًّا، والمتوفّر 300 ⇒ 10 أيام.
        $this->sell($p, 100, qty: 30);
        $this->stock($p, 300);

        $row = $this->row($p, 'day');

        $this->assertEqualsWithDelta(30.0, $row['velocity'], 0.01);
        $this->assertSame(10, $row['days_of_cover']);
    }

    /**
     * والتغطية دون مهلة التوريد تعني «اطلب الآن».
     *
     * وهذا التنبيه هو المقصود: لو طلبتَ اليوم لنفد قبل أن يصل.
     */
    public function test_cover_below_lead_time_asks_to_reorder(): void
    {
        Settings::set('inventory.lead_time_days', '90');

        $p = $this->product('صنف رابح');
        $this->sell($p, 200, qty: 10); // ربح موجب
        $this->stock($p, 50);          // تغطية قصيرة أمام مهلة 90 يومًا

        $row = $this->row($p, 'day');

        $this->assertSame('reorder', $row['verdict']['key']);
        $this->assertGreaterThan(0, $row['suggested_qty']);
    }

    /**
     * والوارد في الطريق يُطرح من الكمية المقترحة.
     *
     * بدونه يقترح النظام شراء ما اشتُري فعلًا وما زال في البحر — أسوأ خطأ في
     * تخطيط الاستيراد.
     */
    public function test_the_suggested_quantity_subtracts_stock_on_hand(): void
    {
        Settings::set('inventory.lead_time_days', '10');
        Settings::set('inventory.safety_days', '0');

        $p = $this->product('صنف');
        $this->sell($p, 100, qty: 10);  // 10 يوميًّا في نطاق اليوم
        $this->stock($p, 40);           // الحاجة 10×10 = 100، والمتوفّر 40 ⇒ 60

        $row = $this->row($p, 'day');

        $this->assertEqualsWithDelta(60.0, $row['suggested_qty'], 0.5);
    }

    /** والصنف الراكد لا يُقترح شراؤه — الشراء له تجميد نقد. */
    public function test_an_idle_product_is_never_suggested_for_reorder(): void
    {
        $p = $this->product('راكد');
        $this->spendOnAds($p, 5); // صُرف عليه إعلان بلا بيع، فيظهر في اللوحة
        $this->stock($p, 100);

        $row = $this->row($p);

        $this->assertSame('idle', $row['verdict']['key']);
        $this->assertEqualsWithDelta(0.0, $row['suggested_qty'], 0.01);
    }

    // ────────── الشاشة ──────────

    /** الصفحة تفتح لمن يملك صلاحية تقارير المبيعات. */
    public function test_the_page_opens_for_an_authorised_user(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.reports.product_decision'))->assertOk();
    }

    /** وتُمنع عمّن لا يملكها. */
    public function test_the_page_requires_the_reports_permission(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('warehouse');

        $this->actingAs($user)->get(route('admin.reports.product_decision'))->assertForbidden();
    }
}
