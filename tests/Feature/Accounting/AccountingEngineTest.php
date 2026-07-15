<?php

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Events\FinancialEventOccurred;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AccountingEngineTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->accounting = app(AccountingService::class);
    }

    private function postEntry(float $amount): JournalEntry
    {
        return $this->accounting->postEntry(['entry_date' => '2026-07-12'], [
            ['account_code' => '1011-0001', 'debit' => $amount, 'credit' => 0],
            ['account_code' => '4010', 'debit' => 0, 'credit' => $amount],
        ]);
    }

    public function test_posted_entry_is_immutable_at_model_level(): void
    {
        $entry = $this->postEntry(100);

        $this->expectException(RuntimeException::class);
        $entry->update(['description' => 'tamper']);
    }

    public function test_posted_entry_cannot_be_deleted(): void
    {
        $entry = $this->postEntry(100);

        $this->expectException(RuntimeException::class);
        $entry->delete();
    }

    public function test_journal_line_is_append_only(): void
    {
        $entry = $this->postEntry(100);
        $line = $entry->lines()->first();

        $this->expectException(RuntimeException::class);
        $line->update(['debit' => 999]);
    }

    public function test_balance_is_derived_from_ledger_not_stored(): void
    {
        $this->postEntry(400);
        $this->postEntry(100);

        // الصندوق (asset، مدين الطبيعة) = 500؛ إيراد المبيعات (revenue، دائن) = 500.
        $this->assertEquals(500, $this->accounting->accountBalance(Account::where('code', '1010')->first()));
        $this->assertEquals(500, $this->accounting->accountBalance(Account::where('code', '4010')->first()));
    }

    public function test_reversal_zeros_the_net_balance(): void
    {
        $entry = $this->postEntry(300);
        $this->accounting->reverse($entry);

        $this->assertEquals(0, $this->accounting->accountBalance(Account::where('code', '1010')->first()));
        $this->assertTrue($entry->fresh()->isReversed());
    }

    public function test_financial_event_generates_balanced_entry_via_listener(): void
    {
        // عزل: الوحدة تُطلق حدثًا؛ المحاسبة تستمع وتولّد القيد (المتطلّب 6).
        event(new FinancialEventOccurred('cash_collection', [
            'amount' => 175, 'date' => '2026-07-12',
        ]));

        $entry = JournalEntry::where('source', 'event')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertEquals('posted', $entry->status);
        $this->assertEquals($entry->lines->sum('debit'), $entry->lines->sum('credit'));
        $this->assertEquals(175, (float) $entry->lines->sum('debit'));
    }

    public function test_unmapped_event_is_a_safe_noop(): void
    {
        $before = JournalEntry::count();
        event(new FinancialEventOccurred('unknown_event', ['amount' => 100]));
        $this->assertEquals($before, JournalEntry::count());
    }
}
