<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «عمولات مستحقّة» في لوحة التحكّم تنقص بالصرف.
 *
 * ## العطب
 *
 * البطاقة كانت تجمع بنود `eligible` و`approved` فحسب. وصرفُ الدفعة عبر
 * `payAmount` يُنشئ سندًا وسجلَّ دفعة و**لا يُحوّل البنود إلى `paid`**: الدفعة
 * مبلغٌ على الحساب قد يغطّي بعض البنود أو يزيد عليها، فلا تُقابَل ببنودٍ بعينها.
 *
 * فبقيت البنود `eligible` بعد الصرف، وبقي الرقم كما كان — وكأنّ المال لم يخرج.
 * ويقرؤه صاحبُ الشركة ديْنًا قائمًا فيدفع مرّتين.
 */
class OutstandingAfterPayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->affiliate = User::factory()->create(['name' => 'سائد شاهين']);
        $this->actingAs($this->admin);

        // بند العمولة يستلزم طلبًا (order_id غير قابل للإفراغ).
        $warehouse = Warehouse::firstOrFail();
        $product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 500, 'wholesale_price' => 300,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($product->defaultVariant, $warehouse, 100, 200);

        $this->order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
        ], [[
            'variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 500,
        ]], (int) now()->year);
    }

    /** بندُ عمولةٍ مستحقّ (eligible) بمبلغٍ معلوم. */
    private function entry(float $amount): CommissionEntry
    {
        return CommissionEntry::create([
            'order_id' => $this->order->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => 'eligible',
            'basis_amount' => $amount,
            'rate' => 100,
            'amount' => $amount,
        ]);
    }

    private function pay(float $amount): void
    {
        app(CommissionService::class)->payAmount(
            actor: $this->admin,
            earnerId: $this->affiliate->id,
            earnerType: 'affiliate',
            amount: $amount,
            treasuryId: Treasury::active()->firstOrFail()->id,
            counterAccountId: Account::where('code', '5040')->firstOrFail()->id,
        );
    }

    private function outstanding(): float
    {
        return app(CommissionService::class)->outstandingTotal();
    }

    // ────────── جوهر العطب ──────────

    /** **الصرف يُنقص المستحقّ بقدره.** */
    public function test_a_payout_lowers_the_outstanding_total(): void
    {
        $this->entry(1000);

        $this->assertEqualsWithDelta(1000.0, $this->outstanding(), 0.01);

        $this->pay(400);

        $this->assertEqualsWithDelta(600.0, $this->outstanding(), 0.01);
    }

    /** وصرفُ الكلّ يُصفّره. */
    public function test_paying_everything_clears_it(): void
    {
        $this->entry(1000);
        $this->pay(1000);

        $this->assertEqualsWithDelta(0.0, $this->outstanding(), 0.01);
    }

    /** واللوحة تعرض الرقم نفسه — لا تحسبه بطريقتها. */
    public function test_the_dashboard_card_matches(): void
    {
        $this->entry(1000);
        $this->pay(400);

        $this->assertEqualsWithDelta(
            600.0,
            (float) $this->get(route('admin.dashboard'))->assertOk()->viewData('pendingCommissions'),
            0.01,
        );
    }

    // ────────── ما لا يُطرح ──────────

    /**
     * **الدفعة المعكوسة لا تُطرح** — مالُها عاد فالدَّين قائم.
     */
    public function test_a_reversed_payout_is_not_deducted(): void
    {
        $this->entry(1000);
        $this->pay(400);

        $voucher = CommissionPayout::latest('id')->firstOrFail()->voucher;
        app(VoucherService::class)->reverse($voucher, $this->admin);

        $this->assertEqualsWithDelta(1000.0, $this->outstanding(), 0.01);
    }

    /** و`pending` خارج الحساب — لم تُستحقّ بعد. */
    public function test_pending_entries_are_excluded(): void
    {
        $this->entry(1000);
        $this->entry(300)->update(['state' => 'pending']);

        $this->assertEqualsWithDelta(1000.0, $this->outstanding(), 0.01);
    }

    /** والملغى والمعكوس من البنود ساقطان. */
    public function test_cancelled_and_reversed_entries_are_excluded(): void
    {
        $this->entry(1000);
        $this->entry(500)->update(['state' => 'cancelled']);
        $this->entry(700)->update(['state' => 'reversed']);

        $this->assertEqualsWithDelta(1000.0, $this->outstanding(), 0.01);
    }

    /** ولا مستحقّ بلا بنود. */
    public function test_it_is_zero_with_no_entries(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->outstanding(), 0.01);
    }
}
