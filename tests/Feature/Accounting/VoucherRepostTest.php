<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherRepostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function postedExpense(float $amount): array
    {
        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();
        $expense = Account::where('code', '5020')->firstOrFail();

        $voucher = app(VoucherService::class)->create('expense', [
            'treasury_id' => $treasury->id, 'amount' => $amount,
            'counter_account_id' => $expense->id, 'party_name' => 'محمد',
            'voucher_date' => '2026-07-15',
        ]);
        app(VoucherService::class)->approve($voucher);
        app(VoucherService::class)->post($voucher);

        return [$voucher->fresh(), $treasury, $expense];
    }

    public function test_editing_posted_expense_reverses_original_and_reposts_new_values(): void
    {
        [$voucher, $treasury, $expense] = $this->postedExpense(100);

        // مصروف 100: مدين 5020 / دائن الخزينة → رصيد الخزينة -100.
        $this->assertEqualsWithDelta(-100, app(TreasuryService::class)->balance($treasury), 0.01);
        $originalEntryId = $voucher->journal_entry_id;

        // تعديل المبلغ إلى 60 (إعادة ترحيل).
        app(VoucherService::class)->repost($voucher, [
            'treasury_id' => $treasury->id, 'amount' => 60,
            'counter_account_id' => $expense->id, 'party_name' => 'محمد',
            'voucher_date' => '2026-07-15',
        ]);

        $voucher->refresh();
        $this->assertEqualsWithDelta(60, (float) $voucher->amount, 0.01);
        $this->assertSame('posted', $voucher->status);
        $this->assertNotSame($originalEntryId, $voucher->journal_entry_id);

        // القيد الأصلي معكوس، والأثر الصافي = القيم الجديدة فقط.
        $this->assertTrue(JournalEntry::find($originalEntryId)->isReversed());
        $this->assertEqualsWithDelta(-60, app(TreasuryService::class)->balance($treasury), 0.01);
        $this->assertEqualsWithDelta(60, app(AccountingService::class)->accountBalance($expense), 0.01);
    }

    public function test_draft_voucher_edits_in_place_without_ledger(): void
    {
        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();
        $expense = Account::where('code', '5020')->firstOrFail();

        $voucher = app(VoucherService::class)->create('expense', [
            'treasury_id' => $treasury->id, 'amount' => 100,
            'counter_account_id' => $expense->id, 'voucher_date' => '2026-07-15',
        ]);

        app(VoucherService::class)->update($voucher, [
            'treasury_id' => $treasury->id, 'amount' => 75,
            'counter_account_id' => $expense->id, 'voucher_date' => '2026-07-15',
        ]);

        $this->assertEqualsWithDelta(75, (float) $voucher->fresh()->amount, 0.01);
        // لا قيد للمسودّة.
        $this->assertNull($voucher->fresh()->journal_entry_id);
    }
}
