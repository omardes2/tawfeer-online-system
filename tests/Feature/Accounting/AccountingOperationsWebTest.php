<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountingOperationsWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_cashbox_pages_render_and_create(): void
    {
        $admin = $this->actor('admin');
        $this->actingAs($admin)->get(route('admin.accounting.cashboxes.index'))->assertOk()->assertSee('CB-MAIN');
        $this->actingAs($admin)->get(route('admin.accounting.cashboxes.create'))->assertOk();

        $this->actingAs($admin)->post(route('admin.accounting.cashboxes.store'), [
            'code' => 'CB-9', 'name' => 'خزينة الفرع', 'currency' => 'SAR', 'opening_balance' => 500,
        ])->assertRedirect(route('admin.accounting.cashboxes.index'));

        $cb = Treasury::where('code', 'CB-9')->first();
        $this->assertNotNull($cb);
        $this->actingAs($admin)->get(route('admin.accounting.cashboxes.show', $cb))->assertOk()->assertSee(number_format(500, 2));
    }

    public function test_receipt_voucher_full_workflow_via_http(): void
    {
        $admin = $this->actor('admin');
        $cashbox = Treasury::where('code', 'CB-MAIN')->first();
        $revenue = Account::where('code', '4010')->value('id');

        // إنشاء (مسودّة)
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.store', 'receipt'), [
            'voucher_date' => now()->toDateString(), 'treasury_id' => $cashbox->id,
            'counter_account_id' => $revenue, 'amount' => 750, 'party_name' => 'عميل',
        ])->assertRedirect();

        $v = FinancialVoucher::where('kind', 'receipt')->first();
        $this->assertSame('draft', $v->status);

        // اعتماد ثم ترحيل عبر HTTP
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.approve', ['receipt', $v]))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.post', ['receipt', $v]))->assertRedirect();

        $this->assertSame('posted', $v->fresh()->status);
        $this->assertNotNull($v->fresh()->journal_entry_id);

        // صفحات العرض والطباعة تُعرَض
        $this->actingAs($admin)->get(route('admin.accounting.vouchers.show', ['receipt', $v]))->assertOk();
        $this->actingAs($admin)->get(route('admin.accounting.vouchers.print', ['receipt', $v]))->assertOk()->assertSee($v->number);
    }

    public function test_transfer_via_http_posts_entry(): void
    {
        $admin = $this->actor('admin');
        $cashbox = Treasury::where('code', 'CB-MAIN')->first();
        $bank = Treasury::where('code', 'BNK-MAIN')->first();
        $revenue = Account::where('code', '4010')->value('id');

        // نموّل الصندوق أولًا.
        $r = $this->postAndPost($admin, 'receipt', ['treasury_id' => $cashbox->id, 'counter_account_id' => $revenue, 'amount' => 1000]);

        $this->actingAs($admin)->post(route('admin.accounting.transfers.store'), [
            'voucher_date' => now()->toDateString(), 'treasury_id' => $cashbox->id, 'counter_treasury_id' => $bank->id, 'amount' => 300,
        ])->assertRedirect();

        $t = FinancialVoucher::where('kind', 'transfer')->first();
        $this->actingAs($admin)->post(route('admin.accounting.transfers.approve', $t))->assertRedirect();
        $this->actingAs($admin)->post(route('admin.accounting.transfers.post', $t))->assertRedirect();
        $this->assertSame('posted', $t->fresh()->status);
    }

    public function test_reports_pages_render(): void
    {
        $admin = $this->actor('admin');
        $this->actingAs($admin)->get(route('admin.accounting.finance_reports.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.accounting.finance_reports.vouchers'))->assertOk();
        $this->actingAs($admin)->get(route('admin.accounting.finance_reports.daily_cash'))->assertOk();
        $this->actingAs($admin)->get(route('admin.accounting.finance_reports.monthly_summary'))->assertOk();
        $cashbox = Treasury::where('code', 'CB-MAIN')->first();
        $this->actingAs($admin)->get(route('admin.accounting.finance_reports.treasury_statement', $cashbox))->assertOk();
    }

    public function test_permission_boundary(): void
    {
        // sales لا يملك صلاحيات المحاسبة التشغيلية.
        $this->actingAs($this->actor('sales'))->get(route('admin.accounting.cashboxes.index'))->assertForbidden();
        $this->actingAs($this->actor('sales'))->get(route('admin.accounting.vouchers.index', 'receipt'))->assertForbidden();
    }

    public function test_posting_requires_post_permission(): void
    {
        // دور بصلاحية إنشاء فقط لا يستطيع الترحيل.
        $role = Role::create(['name' => 'cashier', 'guard_name' => 'web']);
        $role->givePermissionTo(['accounting.receipts.view', 'accounting.receipts.create', 'accounting.receipts.approve']);
        $user = $this->actor('cashier');

        $cashbox = Treasury::where('code', 'CB-MAIN')->first();
        $revenue = Account::where('code', '4010')->value('id');
        $this->actingAs($user)->post(route('admin.accounting.vouchers.store', 'receipt'), [
            'voucher_date' => now()->toDateString(), 'treasury_id' => $cashbox->id, 'counter_account_id' => $revenue, 'amount' => 100,
        ])->assertRedirect();

        $v = FinancialVoucher::first();
        $this->actingAs($user)->post(route('admin.accounting.vouchers.approve', ['receipt', $v]))->assertRedirect();
        // الترحيل ممنوع (لا يملك accounting.receipts.post)
        $this->actingAs($user)->post(route('admin.accounting.vouchers.post', ['receipt', $v]))->assertForbidden();
        $this->assertSame('approved', $v->fresh()->status);
    }

    private function postAndPost(User $admin, string $kind, array $data): FinancialVoucher
    {
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.store', $kind), array_merge(['voucher_date' => now()->toDateString()], $data))->assertRedirect();
        $v = FinancialVoucher::where('kind', $kind)->latest('id')->first();
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.approve', [$kind, $v]));
        $this->actingAs($admin)->post(route('admin.accounting.vouchers.post', [$kind, $v]));

        return $v->fresh();
    }
}
