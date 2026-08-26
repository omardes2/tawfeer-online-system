<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * قسمة الربح في تقرير المبيعات حسب المسوّقين.
 *
 * المسوّق يشتري بسعر قائمته ويبيع بالمفرّق، فللفرق طرفان:
 *
 * ```
 * تكلفة الشراء ──── سعر شراء المسوّق ──── سعر البيع
 *      └── ربح توفير ──┘   └── ربح المسوّق ──┘
 * ```
 *
 * والفحص الحاسم هو **أن مجموعهما يساوي ربح الطلب كاملًا** — فالقسمة لا تخترع
 * ربحًا ولا تُضيّع منه شيئًا.
 */
class AffiliateProfitSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->affiliate = User::factory()->create(['name' => 'سائد شاهين', 'branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');

        $this->product = Product::factory()->create([
            'name' => 'عطر سمارت', 'retail_price' => 100, 'wholesale_price' => 60,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    /**
     * طلبُ مسوّقٍ ببندٍ واحد.
     *
     * الأعمدة تُكتب مباشرةً لأن التقرير يقرؤها هي: سعر البيع، وسعر شراء
     * المسوّق (`wholesale_price_snapshot`)، وتكلفة الشراء (`wholesale_cost_snapshot`).
     */
    private function order(float $price, float $wholesale, float $cost, float $qty = 1, float $shipping = 0): Order
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'affiliate_id' => $this->affiliate->id,
            'status' => 'confirmed',
            'shipping_total' => $shipping,
            'total' => $qty * $price + $shipping,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => $qty,
            'unit_price' => $price,
            'discount' => 0,
            'line_total' => $qty * $price,
            'wholesale_price_snapshot' => $wholesale,
            'wholesale_cost_snapshot' => $cost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order->refresh();
    }

    private function report(): TestResponse
    {
        return $this->actingAs($this->admin)->get(route('admin.reports.sales.by_affiliate', ['range' => 'this_year']));
    }

    /** @return array<string, mixed> */
    private function row(): array
    {
        return $this->report()->viewData('rows')->firstWhere('name', 'سائد شاهين');
    }

    // ────────── القسمة ──────────

    /** ربح المسوّق = سعر البيع − سعر شرائه. */
    public function test_the_affiliate_profit_is_sale_less_his_buying_price(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40);

        $this->assertEqualsWithDelta(40.0, $this->row()['earner_profit'], 0.01);
    }

    /** وربح توفير = سعر شراء المسوّق − تكلفة الشراء الفعلية. */
    public function test_the_company_profit_is_his_buying_price_less_the_real_cost(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40);

        $this->assertEqualsWithDelta(20.0, $this->row()['company_profit'], 0.01);
    }

    /**
     * **ومجموعهما ربحُ الطلب كاملًا.**
     *
     * فالقسمة لا تخترع ربحًا ولا تُضيّع منه شيئًا — وهذا ما يجعل العمودين
     * قابلين للجمع مع بقيّة التقارير.
     */
    public function test_the_two_columns_add_up_to_the_whole_profit(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40);
        $this->order(price: 250, wholesale: 150, cost: 90, qty: 2);

        $row = $this->row();

        $this->assertEqualsWithDelta(
            $row['profit'],
            $row['earner_profit'] + $row['company_profit'],
            0.01,
        );
    }

    /** والكمية تضرب الطرفين معًا. */
    public function test_the_quantity_multiplies_both_sides(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40, qty: 3);

        $row = $this->row();

        $this->assertEqualsWithDelta(120.0, $row['earner_profit'], 0.01);
        $this->assertEqualsWithDelta(60.0, $row['company_profit'], 0.01);
    }

    /**
     * **ورسوم التوصيل خارج الاثنين.**
     *
     * الأرقام من بنود الفاتورة، والرسوم على الطلب لا على البند — فلا تتسرّب
     * إلى ربحٍ ولا إلى بيع.
     */
    public function test_delivery_fees_touch_neither_column(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40, shipping: 25);

        $row = $this->row();

        $this->assertEqualsWithDelta(100.0, $row['sales'], 0.01);
        $this->assertEqualsWithDelta(40.0, $row['earner_profit'], 0.01);
        $this->assertEqualsWithDelta(20.0, $row['company_profit'], 0.01);
    }

    /**
     * وبندٌ بلا لقطة سعر جملة يرتدّ إلى سعر جملة الصنف.
     *
     * فبنودٌ قديمة جُمّدت بصفرٍ كانت ستُظهر الهامش كلّه ربحًا للمسوّق وصفرًا
     * لتوفير.
     */
    public function test_a_missing_snapshot_falls_back_to_the_variant_wholesale(): void
    {
        $this->order(price: 100, wholesale: 0, cost: 40);

        $row = $this->row();

        // سعر جملة الصنف ٦٠.
        $this->assertEqualsWithDelta(40.0, $row['earner_profit'], 0.01);
        $this->assertEqualsWithDelta(20.0, $row['company_profit'], 0.01);
    }

    // ────────── الشاشة ──────────

    /** الشاشة تعرض العمودين باسميهما. */
    public function test_the_screen_shows_both_columns(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40);

        $this->report()
            ->assertOk()
            ->assertSee('ربح المسوّق')
            ->assertSee('ربح توفير');
    }

    /**
     * **وتقرير الموظفين يبقى بعمودٍ واحد.**
     *
     * موظف المبيعات لا يشتري شيئًا، فقسمةُ ربحه عند سعر الجملة تخترع له
     * هامشًا لا يقبضه.
     */
    public function test_the_employee_report_keeps_a_single_profit_column(): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('admin.reports.sales.by_employee', ['range' => 'this_year']));

        $response->assertOk()->assertDontSee('ربح توفير');
        $this->assertFalse($response->viewData('splitProfit'));
    }

    /** والتصدير يحمل العمودين. */
    public function test_the_export_carries_both_columns(): void
    {
        $this->order(price: 100, wholesale: 60, cost: 40);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.sales.by_affiliate', [
            'range' => 'this_year', 'export' => 'csv',
        ]));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('ربح المسوّق', $csv);
        $this->assertStringContainsString('ربح توفير', $csv);
        $this->assertStringContainsString('40.00', $csv);
        $this->assertStringContainsString('20.00', $csv);
    }
}
