<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationalAccountingTest extends TestCase
{
    use RefreshDatabase;

    private TreasuryService $treasuries;

    private VoucherService $vouchers;

    private Treasury $cashbox;

    private Treasury $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->treasuries = app(TreasuryService::class);
        $this->vouchers = app(VoucherService::class);
        $this->cashbox = Treasury::where('code', 'CB-MAIN')->first();
        $this->bank = Treasury::where('code', 'BNK-MAIN')->first();
    }

    private function accountId(string $code): int
    {
        return Account::where('code', $code)->value('id');
    }

    private function postVoucher(string $kind, array $data): FinancialVoucher
    {
        $v = $this->vouchers->create($kind, $data);
        $this->vouchers->approve($v);

        return $this->vouchers->post($v->fresh());
    }

    public function test_opening_balance_posts_entry_and_derives_balance(): void
    {
        $cb = $this->treasuries->create([
            'code' => 'CB-2', 'name' => 'صندوق فرعي', 'type' => 'cash', 'opening_balance' => 1000,
        ]);

        $this->assertEqualsWithDelta(1000.0, $this->treasuries->balance($cb), 0.001);
        $this->assertDatabaseHas('journal_entries', ['reference_type' => 'treasury_opening', 'reference_id' => $cb->id, 'status' => 'posted']);
        $this->assertNotNull($cb->glAccount); // حساب GL مخصّص أُنشئ
    }

    public function test_receipt_voucher_posts_balanced_entry_and_increases_balance(): void
    {
        $v = $this->postVoucher('receipt', [
            'treasury_id' => $this->cashbox->id,
            'counter_account_id' => $this->accountId('4010'), // إيراد المبيعات
            'amount' => 500, 'party_name' => 'عميل نقدي',
        ]);

        $this->assertSame('posted', $v->status);
        $this->assertNotNull($v->journal_entry_id);
        $this->assertEqualsWithDelta(500.0, $this->treasuries->balance($this->cashbox), 0.001);

        // القيد متوازن: مدين الصندوق 500، دائن الإيراد 500.
        $entry = JournalEntry::with('lines.account')->find($v->journal_entry_id);
        $this->assertSame('posted', $entry->status);
        $this->assertEqualsWithDelta(500.0, (float) $entry->lines->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(500.0, (float) $entry->lines->sum('credit'), 0.001);
    }

    public function test_payment_and_expense_decrease_balance(): void
    {
        $this->postVoucher('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 1000]);
        $this->postVoucher('payment', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('2010'), 'amount' => 200]);
        $this->postVoucher('expense', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('5020'), 'amount' => 100, 'category' => 'شحن']);

        $this->assertEqualsWithDelta(700.0, $this->treasuries->balance($this->cashbox), 0.001);
    }

    public function test_other_income_increases_balance(): void
    {
        $this->postVoucher('income', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4020'), 'amount' => 300, 'category' => 'إيراد متنوّع']);
        $this->assertEqualsWithDelta(300.0, $this->treasuries->balance($this->cashbox), 0.001);
    }

    public function test_transfer_moves_between_treasuries_with_one_entry(): void
    {
        $this->postVoucher('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 1000]);

        $t = $this->postVoucher('transfer', [
            'treasury_id' => $this->cashbox->id, 'counter_treasury_id' => $this->bank->id, 'amount' => 400,
        ]);

        $this->assertEqualsWithDelta(600.0, $this->treasuries->balance($this->cashbox), 0.001);
        $this->assertEqualsWithDelta(400.0, $this->treasuries->balance($this->bank), 0.001);
        $this->assertCount(2, JournalEntry::find($t->journal_entry_id)->lines); // قيد واحد بسطرين
    }

    public function test_reversal_undoes_movement_without_deleting(): void
    {
        $v = $this->postVoucher('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 500]);
        $this->vouchers->reverse($v->fresh());

        $this->assertSame('reversed', $v->fresh()->status);
        $this->assertNotNull($v->fresh()->reversal_entry_id);
        $this->assertEqualsWithDelta(0.0, $this->treasuries->balance($this->cashbox), 0.001);
        // الأصل ما زال موجودًا (لا حذف).
        $this->assertNotNull(JournalEntry::find($v->journal_entry_id));
    }

    public function test_duplicate_post_is_idempotent(): void
    {
        $v = $this->vouchers->create('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 250]);
        $this->vouchers->approve($v);
        $this->vouchers->post($v->fresh());
        $this->vouchers->post($v->fresh()); // مرّة ثانية — لا قيد جديد

        $this->assertSame(1, JournalEntry::where('reference_type', 'financial_voucher')->where('reference_id', $v->id)->count());
        $this->assertEqualsWithDelta(250.0, $this->treasuries->balance($this->cashbox), 0.001);
    }

    public function test_cannot_post_unapproved_voucher(): void
    {
        $v = $this->vouchers->create('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 100]);
        $this->expectException(ValidationException::class);
        $this->vouchers->post($v); // draft — ليس approved
    }

    public function test_closed_period_blocks_posting(): void
    {
        FiscalYear::where('name', '2026')->update(['status' => 'closed']);

        $v = $this->vouchers->create('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 100]);
        $this->vouchers->approve($v);

        $this->expectException(ValidationException::class);
        $this->vouchers->post($v->fresh());
    }

    public function test_unsafe_treasury_delete_is_blocked(): void
    {
        $this->postVoucher('receipt', ['treasury_id' => $this->cashbox->id, 'counter_account_id' => $this->accountId('4010'), 'amount' => 100]);

        $this->expectException(ValidationException::class);
        $this->treasuries->delete($this->cashbox->fresh());
    }
}
