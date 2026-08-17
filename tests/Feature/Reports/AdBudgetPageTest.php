<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Foundation\Support\AdminNavigation;
use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdDailySpend;
use App\Modules\Marketing\Models\OperatingDailyCost;
use App\Modules\Marketing\Services\AdBudgetService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * صفحة «الميزانية اليومية».
 *
 * غايتها قرارٌ واحد لكل (صنف × قناة): أوقف / أنقص / ثبّت / زد. وكل ما يلي
 * يحرس شرطًا يجعل ذلك القرار صحيحًا بدل أن يبدو صحيحًا.
 */
class AdBudgetPageTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;

    private AdChannel $channel;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->day = Carbon::yesterday();

        $business = DeliveryBusiness::create([
            'provider' => 'opost', 'external_id' => 'biz-1', 'name' => 'توفير اون لاين', 'is_active' => true,
        ]);
        $this->channel = AdChannel::where('name', 'توفير اون لاين')->firstOrFail();
        $this->channel->update(['delivery_business_id' => $business->id]);

        $this->employee = User::factory()->create([
            'branch_id' => Branch::default()->id,
            'name' => 'فله شاهين',
            'delivery_business_id' => $business->id,
        ]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    /** طلبٌ من موظفة القناة في يومٍ بعينه — سعر 100 وتكلفة 40 ⇒ ربح 60 للقطعة. */
    private function sell(Product $product, ?Carbon $on = null, int $qty = 1): Order
    {
        $this->actingAs($this->employee);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ], [['variant_id' => $product->defaultVariant->id, 'qty' => $qty, 'unit_price' => 100]], 2026);

        $order->forceFill([
            'status' => 'delivered',
            'created_at' => ($on ?? $this->day)->copy()->setTime(12, 0),
        ])->save();

        return $order->refresh();
    }

    private function product(string $name = 'مكنسة كليكي'): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $product->defaultVariant->update(['average_cost' => 40, 'retail_price' => 100]);

        return $product;
    }

    private function spend(Product $product, float $usd, int $conversations, ?Carbon $on = null): AdDailySpend
    {
        return AdDailySpend::create([
            'spend_date' => ($on ?? $this->day)->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'amount_usd' => $usd,
            'fx_rate' => 4,          // صرفٌ مستدير: كل دولار = 4 ₪.
            'conversations' => $conversations,
        ]);
    }

    private function report(): array
    {
        return app(AdBudgetService::class)->report($this->day->copy());
    }

    private function rowFor(array $report, Product $product): array
    {
        return $report['rows']->firstWhere('product_id', $product->id);
    }

    // ────────── الصلاحيات ──────────

    public function test_admin_and_manager_can_open_it(): void
    {
        $this->actingAs($this->admin())->get(route('admin.reports.ad_budget'))->assertOk();
        $this->actingAs($this->withRole('manager'))->get(route('admin.reports.ad_budget'))->assertOk();
    }

    /** الصفحة تكشف التكلفة والربح لكل صنف، فتُغلق دون من يبيع. */
    public function test_everyone_else_is_forbidden(): void
    {
        foreach (['sales', 'warehouse', 'accountant', 'affiliate'] as $role) {
            $this->actingAs($this->withRole($role))
                ->get(route('admin.reports.ad_budget'))
                ->assertForbidden();
        }
    }

    public function test_the_sidebar_shows_it_to_the_admin_only(): void
    {
        $labels = fn () => collect(AdminNavigation::groups())
            ->flatMap(fn ($g) => array_column($g['items'], 'label'))->all();

        $this->actingAs($this->admin());
        $this->assertContains('الميزانية اليومية', $labels());

        $this->actingAs($this->withRole('sales'));
        $this->assertNotContains('الميزانية اليومية', $labels());
    }

    // ────────── الإدخال ──────────

    /** الإدخال المتأخّر يُعاد حتى يستقرّ رقم Meta، فيجب أن يُحدِّث لا أن يتراكم. */
    public function test_saving_the_same_row_twice_updates_it(): void
    {
        $product = $this->product();

        $payload = [
            'spend_date' => $this->day->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $product->id,
            'fx_rate' => 4,
        ];

        $this->actingAs($this->admin())
            ->post(route('admin.reports.ad_budget.spend'), $payload + ['amount_usd' => 8, 'conversations' => 5])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin())
            ->post(route('admin.reports.ad_budget.spend'), $payload + ['amount_usd' => 10.5, 'conversations' => 12])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, AdDailySpend::count());
        $this->assertSame('10.50', AdDailySpend::first()->amount_usd);
        $this->assertSame(12, AdDailySpend::first()->conversations);
    }

    /** والإدخال ممنوع على من له الاطّلاع وحده — الأرقام يُبنى عليها قرارُ إيقاف صرف. */
    public function test_a_viewer_cannot_enter_spend(): void
    {
        $viewer = $this->withRole('manager');
        // الصلاحية تأتي من الدور، فالسحب منه هو ما يصنع «مطّلعًا بلا إدخال».
        Role::findByName('manager')->revokePermissionTo('reports.ad_budget.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->actingAs($viewer)->post(route('admin.reports.ad_budget.spend'), [
            'spend_date' => $this->day->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => $this->product()->id,
            'amount_usd' => 5, 'fx_rate' => 4, 'conversations' => 3,
        ])->assertForbidden();
    }

    /**
     * حذف صفّ الصرف يُعيده إلى «لم يُدخَل» لا إلى صفر.
     *
     * الفرق ليس تجميليًّا: الصفر يعني «لم يُعلَن على هذا الصنف»، والغياب يعني
     * «لم يُنسخ من لوحة Meta بعد» — وعليه يقوم مؤشّر الأيام الناقصة.
     */
    public function test_a_spend_row_can_be_deleted(): void
    {
        $product = $this->product();
        $spend = $this->spend($product, 8, 5);

        $this->actingAs($this->admin())
            ->delete(route('admin.reports.ad_budget.spend.destroy', $spend))
            ->assertSessionHas('success');

        $this->assertSame(0, AdDailySpend::count());
    }

    /** سعر الصرف مخزَّنٌ مع الصفّ: تغيّرُه لاحقًا لا يحرّك ربح الأمس. */
    public function test_the_stored_rate_survives_a_change_in_the_setting(): void
    {
        $product = $this->product();
        $this->spend($product, 10, 20);

        Settings::set('ads.usd_rate', 9.99, 'ads', 'double');

        $this->assertSame(40.0, $this->rowFor($this->report(), $product)['spend']);
    }

    // ────────── أساس الربح ──────────

    /** المرتجع الكامل يخرج من الحساب — الـ5% تُلتقط بلا انتظار التسليم. */
    public function test_a_returned_order_is_excluded(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->sell($product)->forceFill(['status' => 'returned'])->save();

        $row = $this->rowFor($this->report(), $product);

        $this->assertSame(1, $row['orders']);
        $this->assertSame(100.0, $row['sales']);
        $this->assertSame(60.0, $row['profit_before_ads']);
    }

    /** والمرتجع الجزئي يُخصَم بالتناسب لا كلَّه ولا لا شيء منه. */
    public function test_a_partial_return_is_prorated(): void
    {
        $product = $this->product();
        $order = $this->sell($product, qty: 4);
        // `returned_qty` خارج `fillable` — تكتبه وحدةُ المرتجعات بـ`increment`.
        $order->items()->first()->forceFill(['returned_qty' => 1])->save();

        $row = $this->rowFor($this->report(), $product);

        // 3 من 4 باقية: بيع 300، تكلفة 120، ربح 180.
        $this->assertSame(300.0, $row['sales']);
        $this->assertSame(180.0, $row['profit_before_ads']);
    }

    // ────────── المصروف الثابت ──────────

    /**
     * المصروف الثابت في بطاقة اليوم وحدها.
     *
     * توزيعُه على الأصناف يجعل الصنف القليل الطلبات يبدو خاسرًا، فيُوقَف إعلانٌ
     * كان يساهم في تغطيته — ولا يوفّر إيقافُه من المصروف شيئًا.
     */
    public function test_the_fixed_cost_hits_the_day_not_the_product_rows(): void
    {
        $product = $this->product();
        $this->sell($product);          // ربح 60
        $this->spend($product, 5, 10);  // صرف 20 ₪

        $report = $this->report();
        $row = $this->rowFor($report, $product);

        $this->assertSame(40.0, $row['net_profit'], 'دخل المصروف الثابت صفَّ الصنف.');
        $this->assertSame(500.0, $report['totals']['fixed_cost']);
        $this->assertSame(40.0, $report['totals']['profit_after_ads']);
        $this->assertSame(-460.0, $report['totals']['operating_profit']);
    }

    /** وتغييرُه بتاريخ سريان لا يُعيد كتابة ربح الأيام السابقة. */
    public function test_changing_the_fixed_cost_does_not_rewrite_the_past(): void
    {
        OperatingDailyCost::create(['effective_from' => $this->day->copy()->addDay(), 'amount' => 900]);

        $this->assertSame(500.0, $this->report()['totals']['fixed_cost']);
        $this->assertSame(900.0, OperatingDailyCost::amountFor($this->day->copy()->addDay()));
    }

    // ────────── الحكم ──────────

    /** صرفٌ بلا محادثة: إيقافٌ فوري لا ينتظر اكتمال العيّنة. */
    public function test_spend_without_a_single_conversation_stops_immediately(): void
    {
        $product = $this->product('مشد كولومبي');
        $this->spend($product, 8, 0);

        $verdict = $this->rowFor($this->report(), $product)['verdict'];

        $this->assertSame('stop', $verdict['code']);
    }

    /** ودون الحدّ الأدنى للطلبات لا حكم: الرقم القليل ضجيجٌ لا دلالة. */
    public function test_too_few_orders_withhold_the_verdict(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->spend($product, 5, 10);

        $this->assertSame('insufficient', $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    /** العتبات على تكلفة الطلب — بعد بلوغ الحدّ الأدنى. */
    #[DataProvider('cpaBands')]
    public function test_the_cost_per_order_decides(float $usd, string $expected): void
    {
        Settings::set('ads.min_orders', 2, 'ads', 'integer');

        $product = $this->product();
        $this->sell($product);
        $this->sell($product);
        $this->spend($product, $usd, 20);

        $this->assertSame($expected, $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    /** الصرف بالدولار ×4، مقسومًا على طلبين ⇒ تكلفة الطلب. */
    public static function cpaBands(): array
    {
        return [
            'تكلفة 20 ₪ ⇒ زد' => [10, 'increase'],
            'تكلفة 40 ₪ ⇒ ثبّت' => [20, 'hold'],
            'تكلفة 50 ₪ ⇒ أنقص' => [25, 'reduce'],
            'تكلفة 70 ₪ ⇒ أوقف' => [35, 'stop'],
        ];
    }

    /** مبيعاتٌ بلا صرف مُدخَل لا تعني ربحًا — بل إدخالًا ناقصًا. */
    public function test_sales_without_entered_spend_are_flagged_not_praised(): void
    {
        $product = $this->product();
        $this->sell($product);

        $this->assertSame('blocked', $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    /**
     * يومٌ ناقص الإدخال في النافذة يحجب الحكم.
     *
     * الصرف الناقص يجعل تكلفة الطلب تبدو أقلّ ممّا هي، فيُقرأ الخاسر رابحًا.
     */
    public function test_a_day_missing_from_the_window_blocks_the_verdict(): void
    {
        Settings::set('ads.min_orders', 1, 'ads', 'integer');

        $product = $this->product();
        $this->sell($product);
        $this->spend($product, 5, 10);
        // اليوم الأوسط من الثلاثة بلا إدخالٍ أصلًا.
        $this->spend($product, 5, 10, $this->day->copy()->subDays(2));

        $report = $this->report();

        $this->assertSame('blocked', $this->rowFor($report, $product)['verdict']['code']);
        $this->assertArrayHasKey($this->channel->id, $report['missing_days']);
    }

    // ────────── القنوات ──────────

    /** حذف قناةٍ لها طلبات يُفرّغ لقطتها ويضيع إسناد مبيعات ماضية. */
    public function test_a_channel_with_orders_cannot_be_deleted(): void
    {
        $this->sell($this->product());

        $this->actingAs($this->admin())
            ->delete(route('admin.settings.ad_channels.destroy', $this->channel))
            ->assertSessionHas('error');

        $this->assertModelExists($this->channel);
    }

    /** وحسابُ بزنسٍ واحد لا يخدم صفحتين، وإلّا نُسبت مبيعات صفحة لأخرى. */
    public function test_a_business_account_cannot_serve_two_channels(): void
    {
        $other = AdChannel::where('name', 'جاردن هوم')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('admin.settings.ad_channels.update', $other), [
                'name' => $other->name,
                'platform' => 'facebook',
                'delivery_business_id' => $this->channel->delivery_business_id,
            ])
            ->assertSessionHasErrors('delivery_business_id');
    }

    // ────────── العرض ──────────

    public function test_the_page_renders_the_row_and_its_verdict(): void
    {
        $product = $this->product('مكنسة كليكي');
        $this->sell($product);
        $this->spend($product, 5, 10);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.ad_budget', ['day' => $this->day->toDateString()]))
            ->assertOk()
            ->assertSee('مكنسة كليكي', false)
            ->assertSee('توفير اون لاين', false)
            ->assertSee(__('الربح التشغيلي لليوم'), false);
    }

    /**
     * الأرقام ليومٍ واحد، والنافذة للتقييم وحده.
     *
     * الصياغة الأولى قُرئت على أن الصفحة كلّها لثلاثة أيام، فبدا الكشف غير يومي.
     */
    public function test_the_figures_cover_one_day_even_though_the_verdict_looks_back(): void
    {
        $product = $this->product();
        $this->sell($product);                                  // 100 ₪ أمس
        $this->sell($product, $this->day->copy()->subDay());    // 100 ₪ قبله

        $report = $this->report();

        $this->assertSame(1, $report['totals']['orders'], 'تسرّب طلبُ يومٍ آخر إلى أرقام اليوم.');
        $this->assertSame(100.0, $report['totals']['sales']);
        // بينما تحمل النافذة الطلبين معًا للحكم وحده.
        $this->assertSame(2, $this->rowFor($report, $product)['window']['orders']);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.ad_budget', ['day' => $this->day->toDateString()]))
            ->assertOk()
            ->assertSee(__('كل أرقام هذه الصفحة ليوم :d وحده.', ['d' => $this->day->toDateString()]), false);
    }

    /** وصفوف «بلا قناة» تُشرَح بدل أن تُترك تُقرأ خللًا. */
    public function test_unassigned_rows_explain_themselves(): void
    {
        $this->channel->update(['delivery_business_id' => null]);
        $this->employee->update(['delivery_business_id' => null]);
        $this->sell($this->product());

        $this->actingAs($this->admin())
            ->get(route('admin.reports.ad_budget', ['day' => $this->day->toDateString()]))
            ->assertOk()
            ->assertSee('ads:backfill-order-channels', false);
    }

    /** واليوم الافتراضي أمس: أرقام Meta تُنسخ في اليوم التالي. */
    public function test_it_defaults_to_yesterday(): void
    {
        $response = $this->actingAs($this->admin())->get(route('admin.reports.ad_budget'))->assertOk();

        $this->assertSame(Carbon::yesterday()->toDateString(), $response->viewData('day')->toDateString());
    }
}
