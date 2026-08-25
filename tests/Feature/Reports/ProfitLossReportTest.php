<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Accounting\Models\ExpenseCategory;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\ExpenseCategoryService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Reporting\Services\ProfitLossService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * قائمة الأرباح والخسائر.
 *
 * ما يُختبر هنا ليس «هل ظهر رقم» بل **هل القائمة متماسكة**: الأقسام تجمع إلى
 * إجمالها، والمرتجع يُخصَم من الإيراد والتكلفة معًا، والعمولة لا تُعدّ مرّتين،
 * والطلب الواحد لا يقع في دلوين.
 */
class ProfitLossReportTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-01';

    private const TO = '2026-08-31';

    private User $admin;

    private User $seller;

    private User $affiliate;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->seller = User::factory()->create(['name' => 'هالة', 'branch_id' => Branch::default()->id]);
        $this->seller->assignRole('sales');

        $this->affiliate = User::factory()->create(['name' => 'سائد', 'branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');

        $this->product = Product::factory()->create([
            'name' => 'مكنسة', 'retail_price' => 100, 'wholesale_price' => 60,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    /**
     * طلبٌ ببندٍ واحد. الأعمدة تُكتب مباشرةً لأن القائمة تقرؤها هي — لا ما
     * تحسبه خدمةُ الطلبات منها.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes, float $qty = 1, float $price = 100, float $cost = 60, float $returned = 0, float $discount = 0): Order
    {
        $order = Order::factory()->create($attributes + [
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'confirmed',
            'shipping_total' => 0,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => $qty,
            'unit_price' => $price,
            'discount' => $discount,
            'line_total' => $qty * $price - $discount,
            'returned_qty' => $returned,
            'wholesale_cost_snapshot' => $cost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // `created_at` خارج `fillable` للطلب، فالإسناد الجماعي يُسقطه ويصير
        // التاريخ «الآن» — فيقع كلّ طلبٍ في الشهر الجاري ولا يُختبر الفلتر شيئًا.
        $order->newQuery()->whereKey($order->id)->toBase()
            ->update(['created_at' => Carbon::parse($attributes['created_at'] ?? self::FROM.' 10:00:00')]);

        return $order->refresh();
    }

    /** @return array<string, mixed> */
    private function report(string $from = self::FROM, string $to = self::TO): array
    {
        return app(ProfitLossService::class)->report(DateRange::resolve('custom', $from, $to));
    }

    // ────────── الإيرادات ──────────

    /** كل قسمٍ يقرأ عموده: الموظف من الإسناد، والمسوّق من التسويق، والمباشرة من القناة. */
    public function test_revenue_is_split_by_earner(): void
    {
        $this->order(['assigned_to' => $this->seller->id], price: 300);
        $this->order(['affiliate_id' => $this->affiliate->id], price: 500);
        $this->order(['channel' => 'pos'], price: 200);
        $this->order(['channel' => 'web'], price: 100);

        $revenue = $this->report()['revenue'];

        $this->assertEqualsWithDelta(300.0, $revenue['staff'], 0.01);
        $this->assertEqualsWithDelta(500.0, $revenue['affiliates'], 0.01);
        $this->assertEqualsWithDelta(200.0, $revenue['direct'], 0.01);
        $this->assertEqualsWithDelta(100.0, $revenue['store'], 0.01);
    }

    /** والأقسام الأربعة تجمع إلى مبيعات البضاعة بالضبط — لا فرق يُبحث عنه. */
    public function test_the_four_buckets_add_up_to_the_goods_total(): void
    {
        $this->order(['assigned_to' => $this->seller->id], price: 300);
        $this->order(['affiliate_id' => $this->affiliate->id], price: 500);
        $this->order(['channel' => 'pos'], price: 200);
        $this->order(['channel' => 'web'], price: 100);

        $revenue = $this->report()['revenue'];

        $this->assertEqualsWithDelta(
            $revenue['goods'],
            $revenue['staff'] + $revenue['direct'] + $revenue['affiliates'] + $revenue['store'],
            0.01,
        );
    }

    /** وطلبٌ لمسوّقٍ ومُسنَدٌ لموظف يقع في دلوٍ واحد — فلا يُحتسب مرّتين. */
    public function test_an_order_never_falls_in_two_buckets(): void
    {
        $this->order(['affiliate_id' => $this->affiliate->id, 'assigned_to' => $this->seller->id], price: 400);

        $revenue = $this->report()['revenue'];

        $this->assertEqualsWithDelta(400.0, $revenue['affiliates'], 0.01);
        $this->assertEqualsWithDelta(0.0, $revenue['staff'], 0.01);
        $this->assertEqualsWithDelta(400.0, $revenue['goods'], 0.01);
    }

    /** والحسم على البند يُخصَم من الإيراد. */
    public function test_the_line_discount_lowers_revenue(): void
    {
        $this->order(['assigned_to' => $this->seller->id], qty: 2, price: 100, discount: 30);

        $this->assertEqualsWithDelta(170.0, $this->report()['revenue']['goods'], 0.01);
    }

    /** والملغاة خارج القائمة — لم تُبَع. */
    public function test_cancelled_orders_are_excluded(): void
    {
        $this->order(['assigned_to' => $this->seller->id, 'status' => 'cancelled'], price: 999);

        $this->assertEqualsWithDelta(0.0, $this->report()['revenue']['goods'], 0.01);
    }

    /** وخارج الفترة لا يدخل. */
    public function test_the_period_filter_bounds_the_statement(): void
    {
        $this->order(['assigned_to' => $this->seller->id, 'created_at' => '2026-07-15 10:00:00'], price: 700);

        $this->assertEqualsWithDelta(0.0, $this->report()['revenue']['goods'], 0.01);
        $this->assertEqualsWithDelta(700.0, $this->report('2026-07-01', '2026-07-31')['revenue']['goods'], 0.01);
    }

    /**
     * **ورسوم التوصيل المُحصَّلة ليست إيرادًا.**
     *
     * هي مال شركة التوصيل يمرّ بنا، فإدخالها يُضخّم المبيعات بما لم يُبَع.
     */
    public function test_collected_delivery_fees_are_not_revenue(): void
    {
        $order = $this->order(['assigned_to' => $this->seller->id], price: 300);
        $order->newQuery()->whereKey($order->id)->toBase()->update(['shipping_total' => 20]);

        $revenue = $this->report()['revenue'];

        $this->assertEqualsWithDelta(300.0, $revenue['goods'], 0.01);
        $this->assertEqualsWithDelta(300.0, $revenue['total'], 0.01);
        $this->assertArrayNotHasKey('delivery_collected', $revenue);
    }

    // ────────── المرتجع ──────────

    /**
     * **المرتجع الجزئيّ يُخصَم من الإيراد والتكلفة معًا.**
     *
     * خصمُه من الإيراد وحده يُبقي تكلفةَ بضاعةٍ عادت إلى الرفّ في القائمة،
     * فتظهر خسارةٌ وهمية على صنفٍ رابح.
     */
    public function test_a_partial_return_is_prorated_on_both_sides(): void
    {
        $this->order(['assigned_to' => $this->seller->id], qty: 4, price: 100, cost: 60, returned: 1);

        $report = $this->report();

        $this->assertEqualsWithDelta(300.0, $report['revenue']['goods'], 0.01);
        $this->assertEqualsWithDelta(180.0, $report['cogs'], 0.01);
        $this->assertEqualsWithDelta(120.0, $report['gross_profit'], 0.01);
    }

    // ────────── التكلفة ومجمل الربح ──────────

    /** مجمل الربح = الإيراد كلّه ناقص تكلفة البضاعة. */
    public function test_gross_profit_is_revenue_less_cost(): void
    {
        $this->order(['assigned_to' => $this->seller->id], qty: 2, price: 100, cost: 60);

        $report = $this->report();

        $this->assertEqualsWithDelta(200.0, $report['revenue']['total'], 0.01);
        $this->assertEqualsWithDelta(120.0, $report['cogs'], 0.01);
        $this->assertEqualsWithDelta(80.0, $report['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(40.0, $report['gross_margin'], 0.1);
    }

    // ────────── المصاريف ──────────

    /** الإعلان يُحوَّل بسعر صرف يومه لا بسعر اليوم. */
    public function test_ads_are_converted_at_their_own_day_rate(): void
    {
        $channel = AdChannel::create(['name' => 'صفحة المكانس', 'is_active' => true]);

        AdDailySpend::create([
            'spend_date' => self::FROM,
            'ad_channel_id' => $channel->id,
            'product_id' => $this->product->id,
            'amount_usd' => 10,
            'fx_rate' => 3.5,
        ]);

        $this->assertEqualsWithDelta(35.0, $this->report()['expenses']['ads'], 0.01);
    }

    /**
     * **وأجرة الطرود ليست مصروفًا هنا.**
     *
     * التوصيل خرج من الطرفين معًا. وإخراجُه من الإيراد وحده كان سيترك تكلفته
     * مصروفًا بلا مقابل — فيُظهر خسارةً وهمية. مكانُه تقرير تكلفة التوصيل.
     */
    public function test_delivery_paid_is_not_an_expense(): void
    {
        $order = $this->order(['assigned_to' => $this->seller->id], price: 100, cost: 60);
        $order->newQuery()->whereKey($order->id)->toBase()->update(['shipping_total' => 20]);

        $shipment = Shipment::create([
            'number' => 'SHP-'.uniqid(),
            'order_id' => $order->id,
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'delivered',
            'kind' => 'outbound',
            'recipient_name' => 'زبون',
            'recipient_phone' => '0599000000',
            'shipping_cost' => 18,
        ]);

        $shipment->newQuery()->whereKey($shipment->id)->toBase()
            ->update(['created_at' => Carbon::parse(self::FROM.' 10:00:00')]);

        $report = $this->report();

        $this->assertArrayNotHasKey('delivery_paid', $report['expenses']);
        $this->assertEqualsWithDelta(0.0, $report['expenses']['total'], 0.01);
        // ٤٠ ربحُ البضاعة وحدها: لا ٢٠ مضافةً ولا ١٨ مخصومة.
        $this->assertEqualsWithDelta(40.0, $report['net_income'], 0.01);
    }

    /** وسندات الصرف المُرحَّلة تظهر بتصنيفاتها. */
    public function test_posted_expense_vouchers_appear_by_category(): void
    {
        $this->voucher('إيجار', 500);
        $this->voucher('كهرباء', 120);

        $expenses = $this->report()['expenses'];

        $this->assertEqualsWithDelta(620.0, $expenses['vouchers'], 0.01);
        $this->assertSame('إيجار', $expenses['categories']->first()['name']);
    }

    /** وغير المُرحَّل لا يُحتسب — مسودّةٌ لم تصر مصروفًا بعد. */
    public function test_unposted_vouchers_are_ignored(): void
    {
        $this->voucher('إيجار', 500, status: 'draft');

        $this->assertEqualsWithDelta(0.0, $this->report()['expenses']['vouchers'], 0.01);
    }

    /**
     * **العمولة لا تُعدّ مرّتين.**
     *
     * المحتسَب استحقاقُ الفترة. ودفعتُها تُسجَّل سندَ `payment` لا `expense`،
     * فجمعُ سندات المصروف لا يلتقطها — وهذا ما يُثبته السطر الأخير.
     */
    public function test_commissions_are_counted_once(): void
    {
        $order = $this->order(['affiliate_id' => $this->affiliate->id], price: 500);

        CommissionEntry::create([
            'order_id' => $order->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => 'pending',
            'basis' => 500,
            'rate' => 10,
            'amount' => 50,
            'created_at' => Carbon::parse(self::FROM.' 10:00:00'),
        ]);

        $expenses = $this->report()['expenses'];

        $this->assertEqualsWithDelta(50.0, $expenses['commissions'], 0.01);
        $this->assertEqualsWithDelta(0.0, $expenses['vouchers'], 0.01);
    }

    /** والمعكوسة لا تُستحقّ. */
    public function test_reversed_commissions_are_not_an_expense(): void
    {
        $order = $this->order(['affiliate_id' => $this->affiliate->id], price: 500);

        CommissionEntry::create([
            'order_id' => $order->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => 'reversed',
            'basis' => 500,
            'rate' => 10,
            'amount' => 50,
            'created_at' => Carbon::parse(self::FROM.' 10:00:00'),
        ]);

        $this->assertEqualsWithDelta(0.0, $this->report()['expenses']['commissions'], 0.01);
    }

    // ────────── النتيجة ──────────

    /** صافي الدخل = مجمل الربح ناقص المصاريف كلّها. */
    public function test_net_income_is_gross_profit_less_all_expenses(): void
    {
        $this->order(['assigned_to' => $this->seller->id], qty: 10, price: 100, cost: 60);
        $this->voucher('إيجار', 200);

        $report = $this->report();

        $this->assertEqualsWithDelta(400.0, $report['gross_profit'], 0.01);
        $this->assertEqualsWithDelta(200.0, $report['expenses']['total'], 0.01);
        $this->assertEqualsWithDelta(200.0, $report['net_income'], 0.01);
    }

    /** وشهرٌ بلا مبيعات ومصروفُه قائم يعطي خسارةً بالسالب لا صفرًا. */
    public function test_a_month_with_no_sales_shows_a_loss(): void
    {
        $this->voucher('إيجار', 750);

        $report = $this->report();

        $this->assertEqualsWithDelta(-750.0, $report['net_income'], 0.01);
        // لا هامش بلا إيراد: القسمة على صفرٍ ليست صفرًا.
        $this->assertNull($report['net_margin']);
    }

    // ────────── الشاشة ──────────

    /** الصفحة تفتح لمدير النظام وتعرض العمودين. */
    public function test_the_admin_sees_the_statement(): void
    {
        $this->order(['assigned_to' => $this->seller->id], price: 100);

        $this->actingAs($this->admin)
            ->get(route('admin.reports.profit_loss', ['range' => 'custom', 'from' => self::FROM, 'to' => self::TO]))
            ->assertOk()
            ->assertSee('ملخص')
            ->assertSee('إجمالي')
            ->assertSee('صافي الدخل')
            ->assertSee('مبيعات المسوّقين');
    }

    /** ولا تفتح لمن لا يملك صلاحيتها. */
    public function test_a_seller_cannot_open_it(): void
    {
        $this->actingAs($this->seller)->get(route('admin.reports.profit_loss'))->assertForbidden();
    }

    /** والتصدير يُنزَّل ملفًا لا صفحة. */
    public function test_the_export_returns_a_csv(): void
    {
        $this->order(['assigned_to' => $this->seller->id], price: 100);

        $response = $this->actingAs($this->admin)->get(route('admin.reports.profit_loss', [
            'range' => 'custom', 'from' => self::FROM, 'to' => self::TO, 'export' => 'csv',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
    }

    /**
     * سند صرفٍ مُرحَّل بتصنيفٍ باسمه.
     *
     * التصنيف يُنشأ بخدمته لا بالنموذج: `account_id` إلزاميّ ويُفتح له حسابٌ
     * فرعيّ في الشجرة — فإنشاؤه يدويًّا يُنتج تصنيفًا بلا حساب.
     */
    private function voucher(string $category, float $amount, string $status = 'posted'): FinancialVoucher
    {
        $model = ExpenseCategory::where('name', $category)->first()
            ?? app(ExpenseCategoryService::class)->create(['name' => $category, 'is_active' => true]);

        return FinancialVoucher::create([
            'number' => 'EXP-'.uniqid(),
            'kind' => 'expense',
            'status' => $status,
            'voucher_date' => self::FROM,
            'treasury_id' => Treasury::firstOrFail()->id,
            'expense_category_id' => $model->id,
            'amount' => $amount,
            'created_by' => $this->admin->id,
        ]);
    }
}
