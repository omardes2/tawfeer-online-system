<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionPayout;
use App\Modules\Commissions\Models\CommissionTransition;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تعليم بنود العمولة «مدفوعة» يدويًّا — مطابقةٌ لا صرف.
 *
 * ## الفراغ الذي تسدّه
 *
 * الصرف يقع بـ`payAmount`: سندٌ يخرج به المال ويُرحَّل في الدفتر. والدفعة
 * **مبلغٌ على الحساب** لا تُقابَل ببنودٍ بعينها، فتبقى البنود `eligible` بعدها.
 * فيرى المستخدم مالًا خرج وبنودًا كلّها «مستحقّة»، ولا يعرف أيّها غطّاه.
 *
 * ## وما تحرسه هذه الاختبارات
 *
 * أنّ التعليم **لا يمسّ المال**: لا سند، ولا قيد في اليومية، ولا حركة خزينة،
 * ولا تغيّرٌ في «المدفوع» ولا في «المتبقّي». فـ`earned` يجمع `eligible`
 * و`approved` و`paid` سواءً، و«المدفوع» يُقرأ من السندات لا من حالة البند.
 *
 * ولو تغيّر رقمٌ ماليّ بالتعليم لصار وسمٌ في الواجهة يصنع دَينًا أو يمحوه.
 */
class ManualSettlementTest extends TestCase
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

    private function entry(float $amount, string $state = 'eligible'): CommissionEntry
    {
        return CommissionEntry::create([
            'order_id' => $this->order->id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => $state,
            'basis_amount' => $amount,
            'rate' => 100,
            'amount' => $amount,
        ]);
    }

    private function service(): CommissionService
    {
        return app(CommissionService::class);
    }

    private function settle(array $ids, ?string $note = null): array
    {
        return $this->service()->markSettledManually($ids, $this->affiliate->id, 'affiliate', $this->admin, $note);
    }

    // ────────── لا أثر ماليّ ولا محاسبيّ ──────────

    /** **لا سند، ولا قيد، ولا دفعة** — التعليم وسمٌ لا صرف. */
    public function test_it_creates_no_voucher_no_payout_and_no_journal_entry(): void
    {
        $entry = $this->entry(120);
        $journalsBefore = JournalEntry::count();

        $this->settle([$entry->id]);

        $this->assertSame(0, CommissionPayout::count());
        $this->assertSame($journalsBefore, JournalEntry::count());
        $this->assertSame(0, FinancialVoucher::count());
    }

    /** **ولا يتغيّر «المدفوع» ولا «المتبقّي»** — كلاهما يُقرأ من السندات. */
    public function test_the_balance_does_not_move(): void
    {
        $this->entry(120);
        $this->entry(80);
        $before = $this->service()->balance($this->affiliate->id, 'affiliate');

        $this->settle(CommissionEntry::pluck('id')->all());
        $after = $this->service()->balance($this->affiliate->id, 'affiliate');

        $this->assertSame($before, $after);
        $this->assertSame(200.0, $after['earned']);
        $this->assertSame(0.0, $after['paid']);
        $this->assertSame(200.0, $after['outstanding']);
    }

    /** وكذلك إجمالي المستحقّ على الشركة. */
    public function test_the_company_wide_outstanding_does_not_move(): void
    {
        $entry = $this->entry(300);
        $before = $this->service()->outstandingTotal();

        $this->settle([$entry->id]);

        $this->assertSame($before, $this->service()->outstandingTotal());
    }

    /** **والمال الذي خرج فعلًا يبقى محسوبًا** — التعليم لا يُلغي سندًا. */
    public function test_a_real_payout_still_counts_after_marking(): void
    {
        $entry = $this->entry(500);

        $this->service()->payAmount(
            $this->admin, $this->affiliate->id, 'affiliate', 300,
            Treasury::where('code', 'CB-MAIN')->firstOrFail()->id,
            Account::where('code', '5040')->firstOrFail()->id,
            '2026-08-01', '2026-08-31',
        );

        $this->settle([$entry->id]);
        $balance = $this->service()->balance($this->affiliate->id, 'affiliate');

        $this->assertSame(500.0, $balance['earned']);
        $this->assertSame(200.0, $balance['outstanding']);
    }

    // ────────── وما يتغيّر فعلًا ──────────

    /** الحالة تصير «مدفوعة»، وبطاقة «المستحقّة» وحدها تنقص. */
    public function test_the_entry_becomes_paid_and_eligible_card_drops(): void
    {
        $entry = $this->entry(120);
        $this->entry(80);

        $this->settle([$entry->id]);

        $this->assertSame('paid', $entry->fresh()->state);
        $this->assertSame(80.0, $this->service()->statement($this->affiliate->id, 'affiliate')['eligible']);
    }

    /** **وكل تعليم يُدوَّن** — من فعله ومتى وبأي ملاحظة. */
    public function test_every_marking_is_recorded_with_its_note(): void
    {
        $entry = $this->entry(120);

        $this->settle([$entry->id], 'سند PV-2026-000008');

        $transition = CommissionTransition::where('commission_entry_id', $entry->id)->latest('id')->firstOrFail();
        $this->assertSame('eligible', $transition->from_state);
        $this->assertSame('paid', $transition->to_state);
        $this->assertSame($this->admin->id, $transition->actor_id);
        $this->assertStringContainsString('سند PV-2026-000008', $transition->reference);
    }

    /** والمعتمد يُعلَّم كالمستحقّ. */
    public function test_an_approved_entry_can_be_marked_too(): void
    {
        $entry = $this->entry(150, 'approved');

        $result = $this->settle([$entry->id]);

        $this->assertSame('paid', $entry->fresh()->state);
        $this->assertSame(150.0, $result['total']);
    }

    /** والمجموع المُعاد هو مجموع ما عُلِّم فعلًا — لا ما أُرسل. */
    public function test_the_returned_total_counts_only_what_was_marked(): void
    {
        $eligible = $this->entry(120);
        $alreadyPaid = $this->entry(400, 'paid');

        $result = $this->settle([$eligible->id, $alreadyPaid->id]);

        $this->assertSame(1, $result['count']);
        $this->assertSame(120.0, $result['total']);
    }

    // ────────── الحرّاس ──────────

    /** **ولا يُعلَّم بندُ مستفيدٍ آخر** ولو أُرسل معرّفه. */
    public function test_another_earners_entry_is_never_touched(): void
    {
        $other = User::factory()->create(['name' => 'مسوّق آخر']);
        $entry = CommissionEntry::create([
            'order_id' => $this->order->id,
            'earner_id' => $other->id, 'earner_type' => 'affiliate',
            'entry_type' => 'accrual', 'state' => 'eligible',
            'basis_amount' => 900, 'rate' => 100, 'amount' => 900,
        ]);

        try {
            $this->settle([$entry->id]);
            $this->fail('كان يجب أن يُرفض.');
        } catch (ValidationException) {
            // متوقَّع: لا بند قابلًا للتعليم لهذا المستفيد.
        }

        $this->assertSame('eligible', $entry->fresh()->state);
    }

    /** وتحديدٌ لا يحمل بندًا قابلًا للتعليم يُرفض برسالة. */
    public function test_marking_nothing_eligible_is_refused(): void
    {
        $paid = $this->entry(100, 'paid');

        $this->expectException(ValidationException::class);
        $this->settle([$paid->id]);
    }

    // ────────── الشاشة والصلاحية ──────────

    /**
     * **الكشف يُعرَض ومربّعاته فيه.**
     *
     * تُفتح الصفحة فعلًا لا يُختبر المتحكّم وحده: عطبُ قالبٍ لا يظهر في أي
     * اختبارٍ يُرسل نموذجًا، ويسقط الكشف كلّه عند أوّل فتحة.
     */
    public function test_the_statement_page_renders_with_its_checkboxes(): void
    {
        $this->entry(120);

        $this->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate',
        ]))
            ->assertOk()
            ->assertSee('name="entry_ids[]"', false)
            ->assertSee('تعليم كمدفوعة')
            ->assertSee('تحديد الكل في هذه الصفحة');
    }

    /** ولا مربّعات لمن لا يملك الصرف. */
    public function test_the_page_shows_no_checkboxes_without_the_permission(): void
    {
        $this->entry(120);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('commissions.view_team');

        $this->actingAs($viewer)
            ->get(route('admin.commissions.statement', [
                'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate',
            ]))
            ->assertOk()
            ->assertDontSee('name="entry_ids[]"', false)
            ->assertDontSee('تعليم كمدفوعة');
    }

    public function test_the_screen_marks_the_selected_entries(): void
    {
        $entry = $this->entry(120);

        $this->post(route('admin.commissions.settle_manually'), [
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_ids' => [$entry->id],
            'note' => 'PV-8',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('paid', $entry->fresh()->state);
    }

    /** **ومن لا يملك صلاحية الصرف لا يُعلّم.** */
    public function test_a_user_without_the_payout_permission_is_forbidden(): void
    {
        $entry = $this->entry(120);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('commissions.view_team');

        $this->actingAs($viewer)
            ->post(route('admin.commissions.settle_manually'), [
                'earner_id' => $this->affiliate->id,
                'earner_type' => 'affiliate',
                'entry_ids' => [$entry->id],
            ])->assertForbidden();

        $this->assertSame('eligible', $entry->fresh()->state);
    }
}
