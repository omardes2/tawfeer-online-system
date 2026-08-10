<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Events\OrderDelivered;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommissionPaymentTest extends TestCase
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

    /** ينشئ طلبًا لمسوّق ويجعل عمولته مستحقّة (eligible)، ويعيد [affiliate, earned]. */
    private function eligibleAffiliate(float $price = 100, float $cost = 60, float $qty = 2): array
    {
        $affiliate = User::factory()->create(['branch_id' => Branch::default()->id]);
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, $cost);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
            'affiliate_id' => $affiliate->id,
        ], [['variant_id' => $variant->fresh()->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);
        $order->update(['affiliate_id' => $affiliate->id]); // OrderService::create لا يثبّت affiliate_id
        $order->items->each->update(['wholesale_cost_snapshot' => $cost]);

        OrderDelivered::dispatch($order->fresh('items')); // pending
        $this->svc->markEligibleForOrder($order, 'S-TEST');  // eligible

        $earned = $this->svc->balance($affiliate->id, 'affiliate')['earned'];

        return [$affiliate, $earned];
    }

    private function treasury(): Treasury
    {
        return Treasury::create(['code' => 'CB-T', 'name' => 'الصندوق', 'type' => 'cash', 'currency' => 'ILS', 'is_active' => true]);
    }

    private function expenseAccount(): Account
    {
        return Account::create(['code' => '5199', 'name' => 'عمولات المسوّقين', 'type' => 'expense', 'is_postable' => true, 'is_active' => true]);
    }

    public function test_pay_partial_amount_creates_draft_voucher_and_reduces_outstanding(): void
    {
        [$affiliate, $earned] = $this->eligibleAffiliate();
        $this->assertGreaterThan(0, $earned);
        $actor = User::factory()->create();

        $payout = $this->svc->payAmount($actor, $affiliate->id, 'affiliate', 30, $this->treasury()->id, $this->expenseAccount()->id, '2026-08-01', '2026-08-31', 'REF1', 'دفعة أولى');

        // سند صرف مسودّة مرتبط
        $this->assertDatabaseHas('financial_vouchers', [
            'id' => $payout->financial_voucher_id, 'kind' => 'payment', 'status' => 'draft',
            'employee_id' => $affiliate->id, 'amount' => '30.00',
        ]);
        $this->assertDatabaseHas('commission_payouts', ['id' => $payout->id, 'total' => '30.00', 'status' => 'draft']);

        // الرصيد: لم يُرحَّل بعد ⇒ مدفوع 0، قيد الاعتماد 30، المتبقّي = earned − 30
        $b = $this->svc->balance($affiliate->id, 'affiliate');
        $this->assertEquals(0.0, $b['paid']);
        $this->assertEquals(30.0, $b['pending_payout']);
        $this->assertEquals(round($earned - 30, 2), $b['outstanding']);
    }

    public function test_pay_more_than_earned_yields_negative_outstanding_advance(): void
    {
        [$affiliate, $earned] = $this->eligibleAffiliate();
        $actor = User::factory()->create();

        $this->svc->payAmount($actor, $affiliate->id, 'affiliate', $earned + 50, $this->treasury()->id, $this->expenseAccount()->id);

        $b = $this->svc->balance($affiliate->id, 'affiliate');
        $this->assertEquals(-50.0, $b['outstanding']); // سلفة تتجاوز المستحق
    }

    public function test_posted_voucher_counts_as_paid(): void
    {
        [$affiliate] = $this->eligibleAffiliate();
        $actor = User::factory()->create();
        $payout = $this->svc->payAmount($actor, $affiliate->id, 'affiliate', 20, $this->treasury()->id, $this->expenseAccount()->id);

        // محاكاة اعتماد وترحيل المالية للسند
        FinancialVoucher::whereKey($payout->financial_voucher_id)->update(['status' => 'posted']);

        $b = $this->svc->balance($affiliate->id, 'affiliate');
        $this->assertEquals(20.0, $b['paid']);
        $this->assertEquals(0.0, $b['pending_payout']);
    }

    public function test_zero_amount_is_rejected(): void
    {
        [$affiliate] = $this->eligibleAffiliate();
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);
        $this->svc->payAmount($actor, $affiliate->id, 'affiliate', 0, $this->treasury()->id, $this->expenseAccount()->id);
    }

    public function test_statement_page_renders_with_balance_and_period_filter(): void
    {
        [$affiliate] = $this->eligibleAffiliate();
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->actingAs($admin)
            ->get(route('admin.commissions.statement', ['earnerId' => $affiliate->id, 'earner_type' => 'affiliate', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee(__('commissions.outstanding'))
            ->assertSee(__('commissions.pay_profit'))
            ->assertSee(__('commissions.payments_archive'));
    }

    public function test_pay_profit_endpoint_records_payment(): void
    {
        [$affiliate] = $this->eligibleAffiliate();
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->actingAs($admin)->post(route('admin.commissions.pay_profit'), [
            'earner_id' => $affiliate->id, 'earner_type' => 'affiliate', 'amount' => 25,
            'treasury_id' => $this->treasury()->id, 'counter_account_id' => $this->expenseAccount()->id,
            'period_start' => '2026-08-01', 'period_end' => '2026-08-31',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertDatabaseHas('commission_payouts', ['earner_id' => $affiliate->id, 'total' => '25.00', 'status' => 'draft']);
    }
}
