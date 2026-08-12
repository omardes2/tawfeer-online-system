<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionRule;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Events\OrderDelivered;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class CommissionLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private CommissionService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->svc = app(CommissionService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function variant(float $price = 100, float $cost = 60): ProductVariant
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, $cost);

        return $variant->fresh();
    }

    private function order(?User $rep = null, ?User $affiliate = null, float $price = 100, float $cost = 60, float $qty = 2, float $discount = 0): Order
    {
        $variant = $this->variant($price, $cost);
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'assigned_to' => $rep?->id,
        ], [['variant_id' => $variant->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => $discount]], 2026);
        if ($affiliate) {
            $order->update(['affiliate_id' => $affiliate->id]);
        }
        $order->items->each->update(['wholesale_cost_snapshot' => $cost]);

        return $order->fresh('items.variant');
    }

    // ---- الاستحقاق والحساب ----

    public function test_default_one_percent_sales_commission_on_delivery(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep, price: 100, qty: 2); // net item = 200
        OrderDelivered::dispatch($order); // يستحقّ عبر المستمع

        $entry = CommissionEntry::where('earner_type', 'sales')->where('earner_id', $rep->id)->first();
        $this->assertNotNull($entry);
        $this->assertEquals('2.00', $entry->amount);   // 1% من 200
        $this->assertEquals('pending', $entry->state); // ليست eligible قبل التسوية
    }

    public function test_affiliate_margin_from_wholesale_snapshot(): void
    {
        $aff = $this->actor('affiliate');
        $order = $this->order(affiliate: $aff, price: 100, cost: 60, qty: 2); // margin = (100-60)*2 = 80
        $this->svc->accrueForOrder($order);

        $entry = CommissionEntry::where('earner_type', 'affiliate')->first();
        $this->assertEquals('80.00', $entry->amount);
        $this->assertEquals('60.00', $entry->wholesale_cost_snapshot);
    }

    public function test_employee_specific_rule_overrides_default(): void
    {
        $rep = $this->actor('sales');
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.05, 'user_id' => $rep->id, 'priority' => 6]);
        $order = $this->order($rep, price: 100, qty: 2);
        $this->svc->accrueForOrder($order);

        $this->assertEquals('10.00', CommissionEntry::first()->amount); // 5% من 200
    }

    public function test_period_specific_rule_applies_only_in_window(): void
    {
        $rep = $this->actor('sales');
        // قاعدة منتهية → لا تُطبَّق (يعود للافتراضي 1%).
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.10, 'user_id' => $rep->id,
            'period_start' => '2020-01-01', 'period_end' => '2020-12-31', 'priority' => 6]);
        $order = $this->order($rep, price: 100, qty: 2);
        $this->svc->accrueForOrder($order);

        $this->assertEquals('2.00', CommissionEntry::first()->amount); // الافتراضي 1%
    }

    public function test_rule_precedence_employee_beats_branch_beats_global(): void
    {
        $rep = $this->actor('sales');
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.02, 'priority' => 1]); // global
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.03, 'branch_id' => Branch::default()->id, 'priority' => 3]); // branch
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.07, 'user_id' => $rep->id, 'priority' => 6]); // employee

        $order = $this->order($rep, price: 100, qty: 2);
        $this->svc->accrueForOrder($order);
        $this->assertEquals('14.00', CommissionEntry::first()->amount); // 7% من 200 (الموظف يفوز)
    }

    // ---- آلة الحالة والدفع ----

    public function test_full_lifecycle_pending_eligible_approved_paid(): void
    {
        $finance = $this->actor('finance');
        $rep = $this->actor('sales');
        $order = $this->order($rep, price: 100, qty: 2);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();

        $this->svc->markEligibleForOrder($order, 'SETTLE-1', $finance); // 4.6 يستدعيها
        $this->assertEquals('eligible', $entry->fresh()->state);

        $this->svc->approve([$entry->id], $finance);
        $this->assertEquals('approved', $entry->fresh()->state);

        $payout = $this->svc->payout($finance, $rep->id, 'sales', [$entry->id], 'PAY-1');
        $this->assertEquals('paid', $entry->fresh()->state);
        $this->assertEquals('2.00', $payout->total);

        $st = $this->svc->statement($rep->id, 'sales');
        $this->assertEquals(2.0, $st['paid']);
    }

    public function test_state_guard_blocks_skipping_eligible(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();

        // pending → approved مباشرةً ممنوع: approve يعالج eligible فقط → لا اعتماد.
        $this->assertEquals(0, $this->svc->approve([$entry->id], $rep));
        $this->assertEquals('pending', $entry->fresh()->state);
    }

    public function test_double_approval_and_double_payout_prevented(): void
    {
        $finance = $this->actor('finance');
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();
        $this->svc->markEligibleForOrder($order, 'S', $finance);

        $this->assertEquals(1, $this->svc->approve([$entry->id], $finance));
        $this->assertEquals(0, $this->svc->approve([$entry->id], $finance)); // مُعتمد مسبقًا → لا شيء

        $this->svc->payout($finance, $rep->id, 'sales', [$entry->id], 'P1');
        // دفع ثانٍ لنفس البند → لا بنود approved → استثناء.
        $this->expectException(ValidationException::class);
        $this->svc->payout($finance, $rep->id, 'sales', [$entry->id], 'P2');
    }

    public function test_accrual_is_idempotent(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $this->svc->accrueForOrder($order);
        $this->assertEquals(1, CommissionEntry::where('order_id', $order->id)->count());
    }

    // ---- جاهزية المرتجعات ----

    public function test_partial_return_creates_proportional_adjustment(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep, price: 100, qty: 4); // net 400 → 1% = 4.00
        $this->svc->accrueForOrder($order);
        $item = $order->items->first();

        $this->svc->adjustForReturn($item, 2); // إرجاع النصف
        $adj = CommissionEntry::where('entry_type', 'adjustment')->first();
        $this->assertEquals('-2.00', $adj->amount); // نصف العمولة سالبًا
        // الأصل لم يُمسّ.
        $this->assertEquals('4.00', CommissionEntry::where('entry_type', 'accrual')->first()->amount);
    }

    public function test_full_reversal_marks_original_reversed(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep, price: 100, qty: 2);
        $this->svc->accrueForOrder($order);
        $this->svc->reverseForOrder($order);

        $this->assertEquals('reversed', CommissionEntry::where('entry_type', 'accrual')->first()->state);
    }

    // ---- عدم القابلية للتعديل والتفويض ----

    public function test_ledger_amount_is_immutable(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();

        $this->expectException(RuntimeException::class);
        $entry->update(['amount' => 999]);
    }

    public function test_authorization_on_approve_and_payout(): void
    {
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();

        Sanctum::actingAs($this->actor('sales')); // لا صلاحية اعتماد/صرف
        $this->postJson('/api/v1/commissions/approve', ['entry_ids' => [$entry->id]])->assertForbidden();
        $this->postJson('/api/v1/commissions/payout', ['earner_id' => $rep->id, 'earner_type' => 'sales', 'entry_ids' => [$entry->id]])->assertForbidden();

        Sanctum::actingAs($this->actor('finance'));
        $this->postJson('/api/v1/commissions/approve', ['entry_ids' => [$entry->id]])->assertOk();
    }

    // ---- واجهة الإدارة (RTL) ----

    public function test_admin_ledger_and_rules_pages_render(): void
    {
        $finance = $this->actor('finance');
        $finance->update(['email_verified_at' => now()]);
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);

        // الرئيسية: قائمة الأشخاص بأسمائهم (لا بنود) — والاسم يقود إلى كشف الحساب.
        $this->actingAs($finance)->get('/admin/commissions')
            ->assertOk()->assertSee(__('commissions.sales_people'))->assertSee($rep->name)
            ->assertSee(route('admin.commissions.statement', ['earnerId' => $rep->id, 'earner_type' => 'sales'], false));
        // دفتر الحركات التفصيلي انتقل إلى /ledger.
        $this->actingAs($finance)->get('/admin/commissions/ledger?state=pending')
            ->assertOk()->assertSee($order->number);
        // صفحة القواعد: أسماء الأشخاص في القائمة بدل إدخال المعرّفات يدويًا.
        $this->actingAs($finance)->get('/admin/commissions/rules')
            ->assertOk()->assertSee(__('commissions.add_rule'))->assertSee($rep->name);
    }

    /** النسبة تُدخل مئويةً (3 = 3%) وتُخزَّن كسريّة (0.03)، والقاعدة تُطبَّق على صاحبها. */
    public function test_rule_percent_input_is_stored_as_fraction_and_applied(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->first();
        $rep = $this->actor('sales');

        $this->actingAs($admin)->post('/admin/commissions/rules', [
            'earner_type' => 'sales', 'method' => 'percent', 'rate_percent' => 3, 'user_id' => $rep->id,
        ])->assertRedirect();

        $rule = CommissionRule::latest('id')->first();
        $this->assertEqualsWithDelta(0.03, (float) $rule->rate, 0.000001);
        $this->assertSame($rep->id, $rule->user_id);

        // تُطبَّق فعلًا: طلب 200 ⇒ 6.00 بدل 2.00 الافتراضية (1%).
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $this->assertEquals('6.00', CommissionEntry::where('order_id', $order->id)->first()->amount);
    }

    /**
     * ربح المسوّق = سعر البيع − **سعر الجملة** (لا تكلفة الشراء WAC):
     * جملة 70 وبيع 100 ⇒ 30 للقطعة — حتى لو كان متوسط تكلفة الشراء مختلفًا (73.81).
     */
    public function test_affiliate_margin_uses_wholesale_price_not_purchase_cost(): void
    {
        $aff = $this->actor('affiliate');
        $variant = $this->variant(price: 100, cost: 73.81);
        $variant->update(['wholesale_price' => 70]);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'affiliate_id' => $aff->id,
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100, 'discount' => 0]], 2026);

        $this->svc->accrueForOrder($order->fresh('items.variant'));

        $entry = CommissionEntry::where('earner_type', 'affiliate')->first();
        $this->assertEquals('30.00', $entry->amount); // 100 − 70 (لا 26.19)
        $this->assertEquals('70.00', $order->items()->first()->wholesale_price_snapshot);
    }

    /** «هامش الربح كاملًا» للمسوّق: الهامش يُمنح كاملًا بلا نسبة. */
    public function test_margin_method_grants_full_margin_without_rate(): void
    {
        $aff = $this->actor('affiliate');
        CommissionRule::create(['earner_type' => 'affiliate', 'method' => 'margin', 'rate' => 0.25, 'user_id' => $aff->id, 'priority' => 6]);

        $order = $this->order(affiliate: $aff, price: 100, cost: 60, qty: 2); // الهامش = (100−60)×2 = 80
        $this->svc->accrueForOrder($order);

        $entry = CommissionEntry::where('earner_type', 'affiliate')->first();
        $this->assertEquals('80.00', $entry->amount); // الهامش كاملًا — النسبة 25% مُهمَلة
    }

    /**
     * عمولة موظف المبيعات نسبة من **قيمة المبيعات** لا من الهامش، و**بدون التوصيل**:
     * صنف 100×2 مع خصم 10 ورسوم توصيل 20 ⇒ الأساس 190 (لا 210 ولا الهامش).
     */
    public function test_sales_commission_basis_is_sales_value_excluding_delivery(): void
    {
        $rep = $this->actor('sales');
        CommissionRule::create(['earner_type' => 'sales', 'method' => 'percent', 'rate' => 0.10, 'user_id' => $rep->id, 'priority' => 6]);

        $order = $this->order($rep, price: 100, cost: 60, qty: 2, discount: 10);
        $order->update(['shipping_total' => 20, 'total' => (float) $order->total + 20]);
        $this->svc->accrueForOrder($order->fresh('items.variant'));

        $entry = CommissionEntry::where('earner_type', 'sales')->first();
        $this->assertEquals('190.00', $entry->basis);  // (100×2 − 10) — التوصيل خارجها
        $this->assertEquals('19.00', $entry->amount);  // 10% من 190
    }

    /** «هامش الربح» ممنوع لموظفي المبيعات — يُرفض بالتحقّق. */
    public function test_margin_rule_is_rejected_for_sales_staff(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->actingAs($admin)->post('/admin/commissions/rules', [
            'earner_type' => 'sales', 'method' => 'margin',
        ])->assertSessionHasErrors('method');

        $this->assertEquals(0, CommissionRule::where('method', 'margin')->count());
    }

    public function test_admin_approve_via_web(): void
    {
        $finance = $this->actor('finance');
        $finance->update(['email_verified_at' => now()]);
        $rep = $this->actor('sales');
        $order = $this->order($rep);
        $this->svc->accrueForOrder($order);
        $entry = CommissionEntry::first();
        $this->svc->markEligibleForOrder($order, 'S', $finance);

        $this->actingAs($finance)->post('/admin/commissions/approve', ['entry_ids' => [$entry->id]])->assertRedirect();
        $this->assertEquals('approved', $entry->fresh()->state);
    }
}
