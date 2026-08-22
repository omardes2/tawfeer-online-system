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

    /**
     * مدير النظام وحده — مرحلة التجربة.
     *
     * كانت تفتح للمدير أيضًا، وأُغلقت عنه في هجرة
     * `restrict_new_features_to_admin_during_trial`: الصفحة تقترح قراراتِ إنفاق،
     * وقرارٌ مبنيٌّ على شاشةٍ لم تُراجَع يُصرف مالًا حقيقيًّا. تُفتح للمدير من
     * شاشة الأدوار بعد الاعتماد.
     */
    public function test_only_the_system_admin_can_open_it_during_the_trial(): void
    {
        $this->actingAs($this->admin())->get(route('admin.reports.ad_budget'))->assertOk();
        $this->actingAs($this->withRole('manager'))->get(route('admin.reports.ad_budget'))->assertForbidden();
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

    /**
     * اختيار الصنف بالبحث كتابةً لا بقائمة منسدلة.
     *
     * الكتالوج يتجاوز المئة صنف، والتمرير حتى آخر الحروف أبطأ من كتابة كلمة.
     * والقيمة المرسَلة تبقى `product_id` كما هي — تغيّر شكلُ الاختيار لا عقدُه.
     */
    public function test_the_product_is_chosen_by_typing(): void
    {
        $this->product('حزام استقامة الظهر');

        $html = $this->actingAs($this->admin())
            ->get(route('admin.reports.ad_budget'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*type="hidden"[^>]*name="product_id"/', $html);
        $this->assertStringContainsString(__('اكتب اسم الصنف أو رمزه…'), $html);
        // ولا قائمة منسدلة للأصناف بعد اليوم.
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="product_id"/', $html);
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

    // ────────── سعر الصرف ──────────

    /**
     * تعديل المحادثات وحدها لا يدهس سعر صرف الصفّ.
     *
     * كان النموذج المخفيّ يحمل **الافتراضيَّ الحاليّ** لا سعر الصفّ، فأيّ تعديلٍ
     * على صفٍّ أُدخل بسعر 3.05 كان يُعيد كتابته بـ3.7 — فيتغيّر ربح يومٍ مضى
     * بلا أن يقصد أحد، وبلا أثرٍ يدلّ على ما جرى.
     */
    public function test_editing_a_row_keeps_its_own_exchange_rate(): void
    {
        Settings::set('ads.usd_rate', 3.7, 'ads', 'double');

        $product = $this->product();
        $spend = $this->spend($product, 10, 5);
        $spend->forceFill(['fx_rate' => 3.05])->save();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.reports.ad_budget', ['day' => $this->day->toDateString()]))
            ->assertOk()->getContent();

        // النموذج المخفيّ يحمل سعر الصفّ لا الافتراضيّ.
        $this->assertStringContainsString('name="fx_rate" value="3.05"', $html);
    }

    /** وسعر الصرف الافتراضي يُضبَط من الصفحة. */
    public function test_the_default_exchange_rate_is_editable_from_the_page(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.reports.ad_budget.usd_rate'), ['usd_rate' => 3.05])
            ->assertRedirect();

        $this->assertSame(3.05, (float) Settings::get('ads.usd_rate'));
    }

    /** وإعادة احتساب يومٍ بسعرٍ جديد تُصحّح صفوفه هو وحده. */
    public function test_recalculating_a_day_rewrites_only_that_day(): void
    {
        $product = $this->product();
        $today = $this->spend($product, 10, 5);
        $today->forceFill(['fx_rate' => 3.7])->save();

        $earlier = $this->spend($product, 10, 5, $this->day->copy()->subDay());
        $earlier->forceFill(['fx_rate' => 3.7])->save();

        $this->actingAs($this->admin())->post(route('admin.reports.ad_budget.usd_rate'), [
            'usd_rate' => 3.05,
            'apply_day' => $this->day->toDateString(),
        ])->assertRedirect();

        $this->assertEquals(3.05, (float) $today->refresh()->fx_rate);
        // يوم الأمس لم يُمسّ: ربحُه مثبَّت على سعره.
        $this->assertEquals(3.7, (float) $earlier->refresh()->fx_rate);
    }

    /** ولا يضبطه من لا يملك الإدارة. */
    public function test_the_rate_is_gated_by_permission(): void
    {
        $this->actingAs($this->withRole('affiliate'))
            ->post(route('admin.reports.ad_budget.usd_rate'), ['usd_rate' => 3.05])
            ->assertForbidden();
    }

    // ────────── الإعلان المشترك بين أصناف ──────────

    /** إعلانٌ بميزانيةٍ واحدة لعدّة أصناف. */
    private function sharedSpend(array $products, float $usd, int $conversations, ?Carbon $on = null): AdDailySpend
    {
        $spend = AdDailySpend::create([
            'spend_date' => ($on ?? $this->day)->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'product_id' => null,
            'label' => 'إعلان الشتاء',
            'amount_usd' => $usd,
            'fx_rate' => 4,
            'conversations' => $conversations,
        ]);

        $spend->products()->sync(collect($products)->pluck('id')->all());

        return $spend;
    }

    /**
     * الصرف المشترك يُوزَّع بحصّة المبيعات لا بالتساوي.
     *
     * القسمة بالتساوي تجعل الصنف الضعيف كارثيًّا والقويّ بطلًا، فيُقتل الضعيف
     * ظلمًا — والحصّة تُبقي نسبة الإعلان إلى المبيعات واحدةً عليهما.
     */
    public function test_a_shared_ad_is_split_by_sales_share(): void
    {
        $strong = $this->product('صنف قويّ');
        $weak = $this->product('صنف ضعيف');

        $this->sell($strong, qty: 3);   // 300 مبيعات
        $this->sell($weak);             // 100 مبيعات
        $this->sharedSpend([$strong, $weak], 10, 20); // 40 ₪

        $report = $this->report();

        // 300/400 و100/400 ⇒ 30 و10.
        $this->assertEqualsWithDelta(30.0, $this->rowFor($report, $strong)['spend'], 0.01);
        $this->assertEqualsWithDelta(10.0, $this->rowFor($report, $weak)['spend'], 0.01);
    }

    /** ومجموع الموزَّع يساوي المصروف — لا يضيع شيكل ولا يُحتسب مرّتين. */
    public function test_the_allocation_preserves_the_total(): void
    {
        $a = $this->product('أ');
        $b = $this->product('ب');
        $this->sell($a, qty: 3);
        $this->sell($b);
        $this->sharedSpend([$a, $b], 10, 20);

        $this->assertEqualsWithDelta(40.0, $this->report()['totals']['spend'], 0.01);
    }

    /** وإن لم يبع أيٌّ من أصنافه قُسم بالتساوي — وإلّا اختفى الصرف من اللوحة. */
    public function test_a_shared_ad_with_no_sales_splits_evenly(): void
    {
        $a = $this->product('أ');
        $b = $this->product('ب');
        $this->sharedSpend([$a, $b], 10, 20);

        $report = $this->report();

        $this->assertEqualsWithDelta(20.0, $this->rowFor($report, $a)['spend'], 0.01);
        $this->assertEqualsWithDelta(20.0, $this->rowFor($report, $b)['spend'], 0.01);
    }

    /** والحصّة الموزَّعة تُعلَّم بمصدرها فلا تُقرأ رقمًا مُدخَلًا باليد. */
    public function test_the_allocated_share_is_labelled(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->sharedSpend([$product], 5, 10);

        $row = $this->rowFor($this->report(), $product);

        $this->assertEqualsWithDelta(20.0, $row['allocated'], 0.01);
        $this->assertContains('إعلان الشتاء', $row['shared_labels']);
    }

    /** والصنف يجمع صرفه الخاصّ وحصّته من المشترك معًا. */
    public function test_own_and_shared_spend_add_up_on_one_row(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->spend($product, 5, 10);          // 20 ₪ خاصّة
        $this->sharedSpend([$product], 5, 10);  // 20 ₪ موزَّعة

        $row = $this->rowFor($this->report(), $product);

        $this->assertEqualsWithDelta(40.0, $row['spend'], 0.01);
        $this->assertEqualsWithDelta(20.0, $row['allocated'], 0.01);
    }

    /** والحكم يصدر على الإعلان ككلّ — وهو الوحيد القابل للتنفيذ. */
    public function test_a_shared_ad_gets_its_own_verdict(): void
    {
        Settings::set('ads.min_orders', 1, 'ads', 'integer');

        $a = $this->product('أ');
        $b = $this->product('ب');
        $this->sell($a);
        $this->sell($b);
        $this->sharedSpend([$a, $b], 5, 10); // 20 ₪ على طلبين ⇒ تكلفة الطلب 10

        $ads = app(AdBudgetService::class)->sharedAds($this->day);

        $this->assertCount(1, $ads);
        $this->assertSame('إعلان الشتاء', $ads->first()['label']);
        $this->assertSame(2, $ads->first()['orders']);
        $this->assertSame('increase', $ads->first()['verdict']['code']);
    }

    /** وتُحفظ الإعلانات المشتركة من الشاشة. */
    public function test_the_shared_ad_form_saves(): void
    {
        $a = $this->product('أ');
        $b = $this->product('ب');

        $this->actingAs($this->admin())->post(route('admin.reports.ad_budget.shared_spend'), [
            'spend_date' => $this->day->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'label' => 'إعلان مشترك',
            'product_ids' => [$a->id, $b->id],
            'amount_usd' => 10,
            'fx_rate' => 4,
            'conversations' => 20,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, AdDailySpend::whereNull('product_id')->first()->products()->count());
    }

    /** ويُرفض «المشترك» بصنفٍ واحد — مكانه صفّه في الجدول. */
    public function test_a_shared_ad_needs_at_least_two_products(): void
    {
        $this->actingAs($this->admin())->post(route('admin.reports.ad_budget.shared_spend'), [
            'spend_date' => $this->day->toDateString(),
            'ad_channel_id' => $this->channel->id,
            'label' => 'إعلان',
            'product_ids' => [$this->product()->id],
            'amount_usd' => 10,
            'fx_rate' => 4,
            'conversations' => 20,
        ])->assertSessionHasErrors('product_ids');
    }

    /** والصنف في إعلانٍ مشترك لا يُقرأ «بانتظار الإدخال» وقد صُرف عليه فعلًا. */
    public function test_a_product_in_a_shared_ad_is_never_awaiting_input(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->sharedSpend([$product], 5, 10);

        $this->assertNotSame('blocked', $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    // ────────── الإقرار بعدم الإعلان ──────────

    /**
     * صفرٌ مُدخَل صراحةً ليس كغياب الصفّ.
     *
     * الغياب «لم يُنسخ الصرف بعد» فيُحجب الحكم، والصفر «لا إعلان على هذا الصنف»
     * فيظهر ربحه العضويّ. وبلا الفرق يبقى ما لا يُعلَن عليه صامتًا إلى الأبد
     * بشارة «بانتظار الإدخال» بلا شيء يُنتظر.
     */
    public function test_an_entered_zero_means_no_ads_not_no_data(): void
    {
        $product = $this->product();
        $this->sell($product);

        $this->assertSame('blocked', $this->rowFor($this->report(), $product)['verdict']['code']);

        $this->spend($product, 0, 0);

        $this->assertSame('organic', $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    /** والزرّ يملأ فجوات النافذة بصفرٍ صريح فيصدر الحكم. */
    public function test_the_no_ads_button_fills_the_window_gaps(): void
    {
        $product = $this->product();
        $this->sell($product);

        $this->actingAs($this->admin())
            ->post(route('admin.reports.ad_budget.no_ads', [
                $this->channel->id, $product->id, $this->day->toDateString(),
            ]))
            ->assertRedirect();

        $verdict = $this->rowFor($this->report(), $product)['verdict'];

        $this->assertSame('organic', $verdict['code']);
        // النافذة كاملة: يومٌ لكل يوم فيها.
        $this->assertSame(
            (int) app(AdBudgetService::class)->thresholds()['window_days'],
            AdDailySpend::where('product_id', $product->id)->count(),
        );
    }

    /** ولا يدهس يومًا له صرفٌ مُدخَل. */
    public function test_the_no_ads_button_never_overwrites_entered_spend(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->spend($product, 12, 5);

        $this->actingAs($this->admin())
            ->post(route('admin.reports.ad_budget.no_ads', [
                $this->channel->id, $product->id, $this->day->toDateString(),
            ]))
            ->assertRedirect();

        $this->assertEqualsWithDelta(
            12.0,
            (float) AdDailySpend::where('product_id', $product->id)
                ->whereDate('spend_date', $this->day->toDateString())->value('amount_usd'),
            0.01,
        );
    }

    /** ولا يُقرّه من لا يملك الإدارة. */
    public function test_the_no_ads_button_is_gated_by_permission(): void
    {
        $product = $this->product();

        $this->actingAs($this->withRole('affiliate'))
            ->post(route('admin.reports.ad_budget.no_ads', [
                $this->channel->id, $product->id, $this->day->toDateString(),
            ]))
            ->assertForbidden();
    }

    // ────────── عتبات الحكم ──────────

    /**
     * النافذة سبعة أيام وعتبة الطلبات خمسة.
     *
     * عتبةٌ لا تُبلَغ تُصمِت اللوحة عن أغلب صفوفها، فيبقى الصرف بلا قرار —
     * والصمت الدائم ليس حمايةً من الضجيج.
     */
    public function test_the_shipped_window_and_order_floor(): void
    {
        $thresholds = app(AdBudgetService::class)->thresholds();

        $this->assertSame(7, (int) $thresholds['window_days']);
        $this->assertSame(5, (int) $thresholds['min_orders']);
    }

    /** وضبط العتبات من الشاشة يسري على الحكم فورًا. */
    public function test_saving_the_thresholds_changes_the_verdict_at_once(): void
    {
        $product = $this->product();
        $this->sell($product);
        $this->spend($product, 5, 10); // 20 ₪ على طلبٍ واحد

        $this->assertSame('insufficient', $this->rowFor($this->report(), $product)['verdict']['code']);

        $this->actingAs($this->admin())->post(route('admin.reports.ad_budget.thresholds'), [
            'window_days' => 7,
            'min_orders' => 1,
            'cpa_increase_below' => 30,
            'cpa_hold_below' => 45,
            'cpa_reduce_below' => 60,
        ])->assertRedirect();

        $this->assertSame('increase', $this->rowFor($this->report(), $product)['verdict']['code']);
    }

    /**
     * وعتباتٌ غير متصاعدة تُرفَض.
     *
     * الحكم يفحصها بالترتيب، فلو ساوت «زد» عتبةَ «ثبّت» ابتلع الفرعُ الأول ما
     * بعده فلا يصدر «ثبّت» أبدًا — خللٌ صامت لا رسالة خطأ له.
     */
    public function test_thresholds_must_ascend(): void
    {
        $this->actingAs($this->admin())->post(route('admin.reports.ad_budget.thresholds'), [
            'window_days' => 7,
            'min_orders' => 5,
            'cpa_increase_below' => 50,
            'cpa_hold_below' => 45,
            'cpa_reduce_below' => 60,
        ])->assertSessionHasErrors('cpa_hold_below');

        $this->assertSame(5, (int) app(AdBudgetService::class)->thresholds()['min_orders']);
    }

    /** ولا يضبطها من لا يملك الإدارة. */
    public function test_the_thresholds_are_gated_by_permission(): void
    {
        $this->actingAs($this->withRole('affiliate'))
            ->post(route('admin.reports.ad_budget.thresholds'), [
                'window_days' => 7, 'min_orders' => 1,
                'cpa_increase_below' => 30, 'cpa_hold_below' => 45, 'cpa_reduce_below' => 60,
            ])
            ->assertForbidden();
    }
}
