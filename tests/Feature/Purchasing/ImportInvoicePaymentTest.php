<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * السداد بالعملة الأجنبية وفروق الصرف — المرحلة ٤.
 *
 * الدَّين قُيّد بسعر يوم الفاتورة ويُدفع بسعر يوم آخر: يخرج من الخزينة
 * `usd × سعر اليوم` ويُطفأ من ذمّة المورد `usd × سعر الفاتورة`، والفارق فرقُ صرف
 * — وإلا بقيت على المورد قروشٌ لا تُسدَّد أبدًا.
 *
 * المثال: فاتورة 200 قطعة × 45 ¥ ⇒ ذمّة 4,594.40 ₪ بسعر 3.65 ⇒ 1,258.74 $.
 */
class ImportInvoicePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = [
        'fx_rate_to_usd' => 7.15,
        'usd_rate' => 3.65,
        'commission_rate' => 5,
        'cbm_rate_usd' => 180,
        'currency' => 'CNY',
    ];

    private PurchaseInvoiceService $service;

    private AccountingService $accounting;

    private Supplier $supplier;

    private ProductVariant $variant;

    private Treasury $treasury;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(PurchaseInvoiceService::class);
        $this->accounting = app(AccountingService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
        $this->treasury = Treasury::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    private function fxCode(): string
    {
        return config('accounting.purchasing.fx_difference_account');
    }

    private function payableBalance(PurchaseInvoice $invoice): float
    {
        $code = $invoice->supplier->glAccount()->first()?->code ?? config('accounting.purchasing.payable_account');

        return $this->balance($code);
    }

    private function importInvoice(): PurchaseInvoice
    {
        return $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()] + self::HEAD,
            [['variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012]],
        );
    }

    /** الدَّين بالدولار بسعر يوم الفاتورة. */
    private function dueUsd(PurchaseInvoice $invoice): float
    {
        return round($invoice->balanceDue() / (float) $invoice->usd_rate, 2);
    }

    public function test_the_fx_account_exists_in_the_chart(): void
    {
        $account = Account::where('code', $this->fxCode())->first();

        $this->assertNotNull($account, 'حساب فروق أسعار الصرف غير مزروع.');
        $this->assertSame('expense', $account->type);
        $this->assertTrue((bool) $account->is_postable);
    }

    public function test_a_weaker_shekel_records_an_fx_loss_and_clears_the_payable(): void
    {
        $invoice = $this->importInvoice();
        $usd = $this->dueUsd($invoice);
        $payableBefore = $this->payableBalance($invoice);

        // الدولار ارتفع من 3.65 إلى 3.75 ⇒ نخرج شواكل أكثر.
        $this->service->payForeign($invoice, $this->treasury->id, $usd, 3.75);
        $paid = $invoice->fresh();

        $this->assertEqualsWithDelta(0, $paid->balanceDue(), 0.02);
        $this->assertSame('paid', $paid->payment_status);
        // ذمّة المورد صُفّرت بالضبط رغم اختلاف ما خرج من الخزينة.
        $this->assertEqualsWithDelta($payableBefore - (float) $invoice->total, $this->payableBalance($paid), 0.05);
        // الخسارة = الفرق بين السعرين × المبلغ بالدولار.
        $this->assertEqualsWithDelta($usd * (3.75 - 3.65), $this->balance($this->fxCode()), 0.05);
    }

    public function test_a_stronger_shekel_records_an_fx_gain(): void
    {
        $invoice = $this->importInvoice();
        $usd = $this->dueUsd($invoice);

        // الدولار نزل إلى 3.55 ⇒ ندفع شواكل أقلّ ⇒ ربح صرف (رصيد مصروف دائن).
        $this->service->payForeign($invoice, $this->treasury->id, $usd, 3.55);

        $this->assertEqualsWithDelta(0, $invoice->fresh()->balanceDue(), 0.02);
        $this->assertEqualsWithDelta(-$usd * (3.65 - 3.55), $this->balance($this->fxCode()), 0.05);
    }

    public function test_the_treasury_pays_the_actual_cash_not_the_invoice_value(): void
    {
        $invoice = $this->importInvoice();
        $usd = $this->dueUsd($invoice);
        $cashBefore = $this->balance($this->treasury->glAccount->code);

        $this->service->payForeign($invoice, $this->treasury->id, $usd, 3.75);

        // ما خرج فعلًا = المبلغ بالدولار × سعر اليوم، لا قيمة الفاتورة.
        $this->assertEqualsWithDelta($cashBefore - round($usd * 3.75, 2), $this->balance($this->treasury->glAccount->code), 0.05);
    }

    public function test_the_same_rate_records_no_fx_entry(): void
    {
        $invoice = $this->importInvoice();

        $this->service->payForeign($invoice, $this->treasury->id, $this->dueUsd($invoice), 3.65);

        $this->assertEqualsWithDelta(0, $this->balance($this->fxCode()), 0.02);
        $this->assertEqualsWithDelta(0, $invoice->fresh()->balanceDue(), 0.02);
    }

    public function test_a_partial_payment_leaves_the_right_balance(): void
    {
        $invoice = $this->importInvoice();

        $this->service->payForeign($invoice, $this->treasury->id, 500, 3.75);
        $paid = $invoice->fresh();

        // المُسدَّد يُقاس بما أُطفئ من الذمّة (بسعر الفاتورة) لا بما خرج من الخزينة.
        $this->assertEqualsWithDelta(500 * 3.65, (float) $paid->amount_paid, 0.05);
        $this->assertSame('partial', $paid->payment_status);
        $this->assertEqualsWithDelta((float) $invoice->total - 500 * 3.65, $paid->balanceDue(), 0.05);
    }

    public function test_two_partial_payments_at_different_rates_settle_the_invoice(): void
    {
        // الحالة الواقعية: دفعتان بسعرين مختلفين — يجب أن يصل المتبقّي للصفر.
        $invoice = $this->importInvoice();
        $usd = $this->dueUsd($invoice);
        $payableBefore = $this->payableBalance($invoice);

        $this->service->payForeign($invoice, $this->treasury->id, 600, 3.72);
        $this->service->payForeign($invoice->fresh(), $this->treasury->id, round($usd - 600, 2), 3.58);
        $paid = $invoice->fresh();

        $this->assertEqualsWithDelta(0, $paid->balanceDue(), 0.05);
        $this->assertSame('paid', $paid->payment_status);
        // وذمّة المورد صُفّرت من الفاتورة رغم اختلاف سعري الدفعتين.
        $this->assertEqualsWithDelta($payableBefore - (float) $invoice->total, $this->payableBalance($paid), 0.05);
        // وفرقا الصرف تعاكسا جزئيًا: 600×0.07 خسارة و(الباقي)×0.07 ربح.
        $expectedFx = round(600 * (3.72 - 3.65) + ($usd - 600) * (3.58 - 3.65), 2);
        $this->assertEqualsWithDelta($expectedFx, $this->balance($this->fxCode()), 0.05);
    }

    public function test_paying_more_than_the_balance_is_refused(): void
    {
        $invoice = $this->importInvoice();

        $this->expectException(ValidationException::class);
        $this->service->payForeign($invoice, $this->treasury->id, $this->dueUsd($invoice) + 100, 3.65);
    }

    public function test_a_zero_rate_is_refused(): void
    {
        $invoice = $this->importInvoice();

        $this->expectException(ValidationException::class);
        $this->service->payForeign($invoice, $this->treasury->id, 100, 0);
    }

    public function test_a_local_invoice_cannot_be_paid_in_foreign_currency(): void
    {
        $invoice = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50]],
        );

        $this->expectException(ValidationException::class);
        $this->service->payForeign($invoice, $this->treasury->id, 100, 3.65);
    }

    public function test_a_local_payment_still_works_unchanged(): void
    {
        $invoice = $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50]],
        );
        $fxBefore = $this->balance($this->fxCode());

        $this->service->pay($invoice, $this->treasury->id, 500);

        $this->assertEqualsWithDelta(0, $invoice->fresh()->balanceDue(), 0.01);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertEqualsWithDelta($fxBefore, $this->balance($this->fxCode()), 0.01);
    }

    public function test_deleting_a_paid_import_invoice_reverses_the_fx_entry_too(): void
    {
        // لولا عكسُ قيد الفرق لبقيت على المورد ذمّةٌ وهمية بمقداره.
        $payableBefore = $this->balance(config('accounting.purchasing.payable_account'));
        $fxBefore = $this->balance($this->fxCode());

        $invoice = $this->importInvoice();
        $this->service->payForeign($invoice, $this->treasury->id, $this->dueUsd($invoice), 3.75);
        $this->assertNotEqualsWithDelta($fxBefore, $this->balance($this->fxCode()), 0.02);

        $this->service->deletePosted($invoice->fresh('items'));

        $this->assertEqualsWithDelta($fxBefore, $this->balance($this->fxCode()), 0.02);
        $this->assertEqualsWithDelta($payableBefore, $this->payableBalance($invoice), 0.02);
    }

    public function test_the_web_route_pays_in_foreign_currency_when_a_rate_is_sent(): void
    {
        $invoice = $this->importInvoice();
        $usd = $this->dueUsd($invoice);

        $this->post(route('admin.purchasing.invoices.pay', $invoice), [
            'treasury_id' => $this->treasury->id,
            'amount' => $usd,
            'payment_rate' => 3.75,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0, $invoice->fresh()->balanceDue(), 0.02);
        $this->assertEqualsWithDelta($usd * 0.10, $this->balance($this->fxCode()), 0.05);
    }

    public function test_the_payment_box_offers_the_dollar_fields(): void
    {
        $invoice = $this->importInvoice();

        $this->get(route('admin.purchasing.invoices.show', $invoice))
            ->assertOk()
            ->assertSee(__('المبلغ بالدولار'), false)
            ->assertSee(__('سعر الدولار اليوم'), false)
            ->assertSee('payment_rate', false);
    }
}
