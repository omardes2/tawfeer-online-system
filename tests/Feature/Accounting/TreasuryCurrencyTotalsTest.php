<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * «إجمالي الأرصدة» مجموعٌ **لكل عملة** — لا رقمٌ واحد.
 *
 * كان يُجمَع عمودُ الرصيد كما هو، فيُضاف الدولار إلى الشيكل: حسابٌ بـ14,131.90 ₪
 * وآخر بـ98.00 $ يُنتجان «14,229.90» — رقمٌ لا يُقابله مالٌ في الوجود، ولا عملةَ
 * له تُكتب بجانبه، ويُقرأ رصيدًا بالشيكل فيُبنى عليه قرار.
 */
class TreasuryCurrencyTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);
    }

    private function bank(string $code, string $currency, float $opening): Treasury
    {
        return app(TreasuryService::class)->create([
            'code' => $code,
            'name' => 'بنك '.$code,
            'type' => 'bank',
            'currency' => $currency,
            'opening_balance' => $opening,
        ]);
    }

    /** @return Collection<string, float> */
    private function totals()
    {
        return $this->get(route('admin.accounting.banks.index'))
            ->assertOk()->viewData('totals');
    }

    /** **الشيكل والدولار لا يُجمعان في رقمٍ واحد.** */
    public function test_currencies_are_totalled_separately(): void
    {
        $this->bank('BNK-A', 'ILS', 14131.90);
        $this->bank('BNK-B', 'USD', 98.00);

        $totals = $this->totals();

        $this->assertEqualsWithDelta(14131.90, $totals['ILS'], 0.01);
        $this->assertEqualsWithDelta(98.00, $totals['USD'], 0.01);
        // ولا يظهر المجموع الخاطئ في أيّ مكان.
        $this->assertNotContains(14229.90, $totals->all());
    }

    /** وحسابان بالعملة نفسها يُجمعان معًا. */
    public function test_accounts_sharing_a_currency_are_summed(): void
    {
        $this->bank('BNK-A', 'ILS', 1000);
        $this->bank('BNK-B', 'ILS', 250);

        $this->assertEqualsWithDelta(1250.0, $this->totals()['ILS'], 0.01);
    }

    /** والعملة الأساسية تتصدّر — هي ما يُقرأ أوّلًا ويُقارَن به. */
    public function test_the_base_currency_leads(): void
    {
        $this->bank('BNK-USD', 'USD', 98);
        $this->bank('BNK-ILS', 'ILS', 500);

        $this->assertSame(config('app.currency', 'ILS'), $this->totals()->keys()->first());
    }

    /** والصفحة تكتب رمز العملة بجانب كل مجموع — رقمٌ بلا عملةٍ لا يُقرأ. */
    public function test_each_total_is_labelled_with_its_currency(): void
    {
        $this->bank('BNK-A', 'ILS', 1000);
        $this->bank('BNK-B', 'USD', 98);

        $this->get(route('admin.accounting.banks.index'))
            ->assertOk()
            ->assertSee('USD')
            ->assertSee('لكل عملة مجموعها');
    }

    /** والخزائن النقدية تتبع القاعدة نفسها. */
    public function test_cashboxes_follow_the_same_rule(): void
    {
        app(TreasuryService::class)->create([
            'code' => 'CASH-USD', 'name' => 'صندوق دولار',
            'type' => 'cash', 'currency' => 'USD', 'opening_balance' => 40,
        ]);

        $totals = $this->get(route('admin.accounting.cashboxes.index'))
            ->assertOk()->viewData('totals');

        $this->assertEqualsWithDelta(40.0, $totals['USD'], 0.01);
    }
}
