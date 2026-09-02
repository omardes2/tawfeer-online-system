<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * حذف دفعةٍ من أرشيف الدفعات — بعكس سندها لا بمحوه.
 *
 * ## الأصل المحاسبيّ
 *
 * السند وثيقةٌ خرج بها مالٌ من الخزينة وله قيدٌ مُرحَّل. ومحوُه يُنقص الخزينة
 * رصيدًا بلا سبب ظاهر ويترك قيدًا يتيمًا (BR-ACC-09). فالحذف هنا **قيدٌ عاكس**:
 * المال يعود في الدفاتر، ويبقى الأصلُ والعكسُ مقروءين — «صُرف ثم أُلغي» لا «لم
 * يُصرف قطّ».
 *
 * ## وأثره على الرصيد مقصود
 *
 * المدفوع يُعدّ من السندات المُرحَّلة وحدها، فعكسُ السند يرفع المتبقّي بمقدار
 * الدفعة. وهذا صحيح: ماله عاد إلى الخزينة فصار له عند الشركة.
 */
class DeletePayoutTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $earner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->earner = User::factory()->create(['name' => 'سائد شاهين']);
        $this->actingAs($this->admin);
    }

    private function service(): CommissionService
    {
        return app(CommissionService::class);
    }

    /** حركةُ استحقاقٍ تجعل للمسوّق رصيدًا يُصرف منه. */
    private function earn(float $amount): CommissionEntry
    {
        return CommissionEntry::create([
            'order_id' => Order::factory()->create(['affiliate_id' => $this->earner->id])->id,
            'earner_id' => $this->earner->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => 'eligible',
            'basis' => $amount, 'rate' => 1, 'amount' => $amount,
        ]);
    }

    private function pay(float $amount): CommissionPayout
    {
        return $this->service()->payAmount(
            $this->admin, $this->earner->id, 'affiliate', $amount,
            Treasury::where('code', 'CB-MAIN')->firstOrFail()->id,
            Account::where('code', '5040')->firstOrFail()->id,
            '2026-08-01', '2026-08-31',
        );
    }

    private function cash(): float
    {
        return round(app(AccountingService::class)->accountBalance(
            Account::where('code', '1011-0001')->firstOrFail(),
        ), 2);
    }

    private function balance(): array
    {
        return $this->service()->balance($this->earner->id, 'affiliate');
    }

    // ────────── العكس لا المحو ──────────

    /** **السند يُعكس ولا يُحذف**، والدفعة تُخفى حذفًا ناعمًا. */
    public function test_a_posted_voucher_is_reversed_not_erased(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);
        $voucher = $payout->voucher()->first();

        $result = $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame('reversed', $result['voucher_action']);
        $this->assertSame('reversed', $voucher->fresh()->status);
        $this->assertNotNull($voucher->fresh()->reversal_entry_id);
        $this->assertSoftDeleted('commission_payouts', ['id' => $payout->id]);
    }

    /** **والمال يعود إلى الخزينة في الدفاتر.** */
    public function test_the_money_returns_to_the_treasury(): void
    {
        $this->earn(1000);
        $before = $this->cash();
        $payout = $this->pay(400);

        $this->assertSame(round($before - 400, 2), $this->cash());

        $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame($before, $this->cash());
    }

    /** **ويرتفع المتبقّي للمسوّق** — ماله عاد فصار له عند الشركة. */
    public function test_the_outstanding_rises_by_the_deleted_amount(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);

        $this->assertSame(400.0, $this->balance()['paid']);
        $this->assertSame(600.0, $this->balance()['outstanding']);

        $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame(0.0, $this->balance()['paid']);
        $this->assertSame(1000.0, $this->balance()['outstanding']);
    }

    /** وكذلك إجمالي المستحقّ على الشركة. */
    public function test_the_company_wide_outstanding_rises_too(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);
        $before = $this->service()->outstandingTotal();

        $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame(round($before + 400, 2), $this->service()->outstandingTotal());
    }

    // ────────── حالات السند الأخرى ──────────

    /** **سندٌ معكوسٌ أصلًا لا يُعكس مرّتين ولا يتغيّر رصيد.** */
    public function test_an_already_reversed_voucher_is_left_alone(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);
        app(VoucherService::class)->reverse($payout->voucher()->first());

        $before = $this->balance();
        $cash = $this->cash();

        $result = $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame('untouched', $result['voucher_action']);
        $this->assertSame($before, $this->balance());
        $this->assertSame($cash, $this->cash());
        $this->assertSoftDeleted('commission_payouts', ['id' => $payout->id]);
    }

    /** والدفعة المحذوفة تخرج من الأرشيف. */
    public function test_a_deleted_payout_leaves_the_archive(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);

        $this->service()->deletePayout($payout, $this->admin);

        $this->assertSame(0, CommissionPayout::count());
        $this->assertSame(1, CommissionPayout::withTrashed()->count());
    }

    // ────────── الحرّاس ──────────

    /**
     * **دفعةٌ بلا سند تُرفض.**
     *
     * تُحتسب مصروفةً في الرصيد (دفعةٌ قديمة)، فحذفُها يرفع المتبقّي بلا قيدٍ
     * يقابله — مالٌ يُطالَب به مرّتين بلا أثرٍ في الدفاتر.
     */
    public function test_a_payout_without_a_voucher_is_refused(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);
        CommissionPayout::whereKey($payout->id)->update(['financial_voucher_id' => null]);

        $this->expectException(ValidationException::class);
        $this->service()->deletePayout($payout->fresh(), $this->admin);
    }

    /** ودفعةٌ ببنودٍ مربوطة تُترك للمحاسب — إرجاع `paid` ليس انتقالًا مسموحًا. */
    public function test_a_payout_with_linked_entries_is_refused(): void
    {
        $entry = $this->earn(500);
        $this->service()->approve([$entry->id], $this->admin);

        $payout = $this->service()->payout($this->admin, $this->earner->id, 'affiliate', [$entry->id]);

        $this->expectException(ValidationException::class);
        $this->service()->deletePayout($payout, $this->admin);
    }

    // ────────── الشاشة والصلاحية ──────────

    public function test_deleting_from_the_screen_reports_the_effect(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);

        $this->delete(route('admin.commissions.payouts.destroy', $payout))
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame('reversed', $payout->voucher()->first()->fresh()->status);
        $this->assertSame(1000.0, $this->balance()['outstanding']);
    }

    /** **ومن لا يملك صلاحية الصرف لا يحذف.** */
    public function test_a_user_without_the_payout_permission_is_forbidden(): void
    {
        $this->earn(1000);
        $payout = $this->pay(400);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('commissions.view_team');

        $this->actingAs($viewer)
            ->delete(route('admin.commissions.payouts.destroy', $payout))
            ->assertForbidden();

        $this->assertSame('posted', $payout->voucher()->first()->fresh()->status);
        $this->assertSame(400.0, $this->balance()['paid']);
    }
}
