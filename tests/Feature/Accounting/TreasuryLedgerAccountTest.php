<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TreasuryLedgerAccountTest extends TestCase
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

    public function test_new_cashbox_gl_account_nests_under_cash_1010(): void
    {
        $treasury = app(TreasuryService::class)->create([
            'code' => 'CB-2026-001', 'name' => 'صندوق طه', 'type' => 'cash', 'opening_balance' => 0,
        ]);

        $cashId = Account::where('code', '1010')->value('id');
        $account = $treasury->glAccount;

        $this->assertSame('T-CB-2026-001', $account->code);
        $this->assertSame($cashId, $account->parent_id);
        $this->assertSame('asset', $account->type);
        $this->assertTrue($account->is_postable);
    }

    public function test_new_bank_gl_account_nests_under_bank_1020(): void
    {
        $treasury = app(TreasuryService::class)->create([
            'code' => 'BNK-2026-001', 'name' => 'بنك الشركة', 'type' => 'bank', 'opening_balance' => 0,
        ]);

        $bankId = Account::where('code', '1020')->value('id');
        $this->assertSame($bankId, $treasury->glAccount->parent_id);
    }

    public function test_cash_control_balance_rolls_up_cashbox(): void
    {
        $treasury = app(TreasuryService::class)->create([
            'code' => 'CB-2026-002', 'name' => 'صندوق فرعي', 'type' => 'cash', 'opening_balance' => 500,
        ]);

        // الرصيد الافتتاحي 500 على الحساب الفرعي، ورصيد «الصندوق 1010» الرئيس يشمله.
        $this->assertEqualsWithDelta(500, app(TreasuryService::class)->balance($treasury), 0.01);
        $cash = Account::where('code', '1010')->firstOrFail();
        $this->assertEqualsWithDelta(500, app(AccountingService::class)->accountBalance($cash), 0.01);
    }
}
