<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رصيد المورد يُشتقّ من **دفتر الأستاذ** — لا من أعمدة الفواتير.
 *
 * كانت شاشتا الموردين تحسبانه بمعادلتين مختلفتين، فيقرأ المستخدم رقمين لمورّدٍ
 * واحد في الدقيقة نفسها:
 *
 * ```
 * القائمة:  افتتاحي + Σ(إجمالي الفاتورة − amount_paid)   ← لا ترى الدفعة على الحساب
 * الصفحة:   افتتاحي + Σالفواتير − Σسندات الصرف           ← لا ترى فرق الصرف
 * ```
 *
 * وكلتاهما ناقصة: ذمّة المورد تتحرّك بفروق الصرف والدفعات على الحساب أيضًا،
 * والدفتر وحده يعرفها لأنها كلّها تمرّ عليه.
 */
class SupplierLedgerBalanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Supplier $supplier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $this->supplier = app(SupplierService::class)->create([
            'name' => 'بضاعة الصين', 'code' => '4001',
        ]);

        $this->product = Product::factory()->create([
            'name' => 'مشد كولومبي', 'retail_price' => 200, 'wholesale_price' => 120,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    /** فاتورة استيراد مُرحّلة بالدولار — قيمتُها بالشيكل = المبلغ × سعر يوم الفاتورة. */
    private function importInvoice(float $usd, float $rateOnInvoice)
    {
        return app(PurchaseInvoiceService::class)->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'USD',
            'fx_rate_to_usd' => 1,
            'usd_rate' => $rateOnInvoice,
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => 1,
            'unit_price_foreign' => $usd,
            'unit_cost' => $usd * $rateOnInvoice,
            'cbm_per_unit' => 0,
            'tax_rate' => 0,
        ]]);
    }

    private function localInvoice(float $total)
    {
        return app(PurchaseInvoiceService::class)->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => 1, 'unit_cost' => $total, 'tax_rate' => 0,
        ]]);
    }

    private function treasury(): Treasury
    {
        return Treasury::active()->firstOrFail();
    }

    private function service(): SupplierService
    {
        return app(SupplierService::class);
    }

    /** رصيد الصفّ كما تعرضه القائمة. */
    private function listedBalance(): float
    {
        $row = Supplier::query()
            ->select('suppliers.*')
            ->selectRaw(SupplierService::ledgerBalanceExpression().' as ledger_balance')
            ->whereKey($this->supplier->id)
            ->firstOrFail();

        return round((float) $row->ledger_balance, 2);
    }

    // ────────── الحالة التي كشفت الخلل ──────────

    /**
     * **السداد بالدولار: الشاشتان تتّفقان، والفرق يخرج فرقَ صرفٍ لا دَينًا.**
     *
     * فاتورةٌ بـ1000$ بسعر 3.60 ⇒ ذمّة 3,600 ₪. تُسدَّد بـ1000$ بسعر 3.55 ⇒
     * يخرج من الخزينة 3,550 ₪ ويُطفأ من الذمّة 3,600. والفارق 50 ربحُ صرف.
     * فالرصيد صفرٌ لا 50.
     */
    public function test_a_foreign_payment_settles_the_supplier_to_zero(): void
    {
        $invoice = $this->importInvoice(usd: 1000, rateOnInvoice: 3.60);

        app(PurchaseInvoiceService::class)->payForeign(
            $invoice->fresh(), $this->treasury()->id, foreignAmount: 1000, paymentRate: 3.55,
        );

        $this->assertEqualsWithDelta(0.0, $this->service()->ledgerBalance($this->supplier), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->listedBalance(), 0.01);
    }

    /** والشاشتان تقرآن الرقم نفسه — وهو جوهر العطب المُبلَّغ عنه. */
    public function test_both_screens_agree_after_a_foreign_payment(): void
    {
        $invoice = $this->importInvoice(usd: 1000, rateOnInvoice: 3.60);
        app(PurchaseInvoiceService::class)->payForeign(
            $invoice->fresh(), $this->treasury()->id, foreignAmount: 500, paymentRate: 3.55,
        );

        $this->assertEqualsWithDelta(
            $this->service()->ledgerBalance($this->supplier),
            $this->listedBalance(),
            0.01,
        );
    }

    /**
     * **والفرق يُعرض على الشاشة تسويةً بدل أن يختفي.**
     *
     * البطاقات الثلاث كانت لا تُجمَع: المشتريات − المدفوعات ≠ الرصيد، بلا سطرٍ
     * يقول لماذا.
     */
    public function test_the_supplier_page_shows_the_adjustment(): void
    {
        $invoice = $this->importInvoice(usd: 1000, rateOnInvoice: 3.60);
        app(PurchaseInvoiceService::class)->payForeign(
            $invoice->fresh(), $this->treasury()->id, foreignAmount: 1000, paymentRate: 3.55,
        );

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('تسويات');
    }

    // ────────── كشف الحساب ──────────

    /**
     * **الرصيد الافتتاحي يظهر سطرًا في الكشف.**
     *
     * كان يدخل الرصيد المتحرّك صامتًا، فيبدأ الكشف من رقمٍ لا يُفسّره شيء: تُقرأ
     * أوّلُ فاتورةٍ بـ13,208 ويقفز الرصيد إلى −136,088 بلا سبب ظاهر.
     */
    public function test_the_opening_balance_appears_as_a_row(): void
    {
        $this->service()->syncOpeningBalance($this->supplier, -5000);
        $this->localInvoice(1000);

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('رصيد افتتاحي');
    }

    /** وفرق الصرف يظهر سطرًا كذلك — لا يختفي بين الفاتورة والدفعة. */
    public function test_the_fx_difference_appears_as_a_row(): void
    {
        $invoice = $this->importInvoice(usd: 1000, rateOnInvoice: 3.60);
        app(PurchaseInvoiceService::class)->payForeign(
            $invoice->fresh(), $this->treasury()->id, foreignAmount: 1000, paymentRate: 3.55,
        );

        $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()
            ->assertSee('فرق صرف');
    }

    /**
     * **وآخر سطرٍ في الكشف يساوي بطاقة «الرصيد المتبقّي».**
     *
     * وهو الشرط الذي يجعل الكشف مستندًا يُراجَع: رقمان مختلفان على شاشةٍ واحدة
     * يُبطلان الثقة بكليهما.
     */
    public function test_the_statement_ends_at_the_card_balance(): void
    {
        $this->service()->syncOpeningBalance($this->supplier, -5000);
        $invoice = $this->importInvoice(usd: 1000, rateOnInvoice: 3.60);
        app(PurchaseInvoiceService::class)->payForeign(
            $invoice->fresh(), $this->treasury()->id, foreignAmount: 400, paymentRate: 3.55,
        );

        $response = $this->get(route('admin.purchasing.suppliers.show', $this->supplier))->assertOk();

        $statement = $response->viewData('statement');
        $balance = $response->viewData('balance');

        $this->assertEqualsWithDelta($balance, $statement->last()['balance'], 0.01);
        $this->assertEqualsWithDelta(
            $this->service()->ledgerBalance($this->supplier->fresh()), $balance, 0.01,
        );
    }

    // ────────── الدفعة على الحساب ──────────

    /**
     * **دفعةٌ بلا فاتورة تُنقص الرصيد.**
     *
     * كانت القائمة لا تراها أصلًا لأنها لا تمسّ `amount_paid` لأيّ فاتورة، فتُبقي
     * على المورد دَينًا سُدّد.
     */
    public function test_an_on_account_payment_lowers_the_listed_balance(): void
    {
        $invoice = $this->localInvoice(1000);
        app(PurchaseInvoiceService::class)->pay($invoice->fresh(), $this->treasury()->id, 400);

        $before = $this->listedBalance();

        // دفعةٌ على الحساب: سند صرفٍ للمورد بلا ربطٍ بفاتورة.
        app(VoucherService::class)->post(
            app(VoucherService::class)->approve(
                app(VoucherService::class)->create('payment', [
                    'treasury_id' => $this->treasury()->id,
                    'amount' => 250,
                    'counter_account_id' => $this->supplier->glAccount()->firstOrFail()->id,
                    'supplier_id' => $this->supplier->id,
                    'description' => 'دفعة على الحساب',
                    'voucher_date' => now()->toDateString(),
                ]),
            ),
        );

        $this->assertEqualsWithDelta($before - 250, $this->listedBalance(), 0.01);
        $this->assertEqualsWithDelta($this->listedBalance(), $this->service()->ledgerBalance($this->supplier), 0.01);
    }

    // ────────── الحالات العادية ──────────

    /** الفاتورة المحلية المُرحّلة تزيد الرصيد بقيمتها. */
    public function test_a_posted_invoice_raises_the_balance(): void
    {
        $this->localInvoice(1000);

        $this->assertEqualsWithDelta(1000.0, $this->service()->ledgerBalance($this->supplier), 0.01);
        $this->assertEqualsWithDelta(1000.0, $this->listedBalance(), 0.01);
    }

    /** والدفعة الجزئية تُنقصه بقدرها. */
    public function test_a_partial_payment_lowers_the_balance(): void
    {
        $invoice = $this->localInvoice(1000);
        app(PurchaseInvoiceService::class)->pay($invoice->fresh(), $this->treasury()->id, 400);

        $this->assertEqualsWithDelta(600.0, $this->service()->ledgerBalance($this->supplier), 0.01);
        $this->assertEqualsWithDelta(600.0, $this->listedBalance(), 0.01);
    }

    /** والرصيد الافتتاحي يدخل الحساب — لأنه مُرحَّل في الدفتر لا رقمٌ على الصفّ. */
    public function test_the_opening_balance_is_included(): void
    {
        $this->service()->syncOpeningBalance($this->supplier, 750);

        $this->assertEqualsWithDelta(750.0, $this->service()->ledgerBalance($this->supplier->fresh()), 0.01);
        $this->assertEqualsWithDelta(750.0, $this->listedBalance(), 0.01);
    }

    /** والمورّد بلا حركة رصيدُه صفر. */
    public function test_an_untouched_supplier_reads_zero(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->service()->ledgerBalance($this->supplier), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->listedBalance(), 0.01);
    }

    /** وفلتر «بأرصدة» يتبع التعبير نفسه فلا يخالف ما يُعرض. */
    public function test_the_with_balance_filter_matches_what_is_shown(): void
    {
        $this->localInvoice(1000);

        $this->get(route('admin.purchasing.suppliers.index', ['filter' => 'with_balance']))
            ->assertOk()->assertSee('بضاعة الصين');
    }

    /** والمورّد المُسدَّد بالكامل يخرج من «بأرصدة». */
    public function test_a_settled_supplier_is_excluded_from_the_filter(): void
    {
        $invoice = $this->localInvoice(1000);
        app(PurchaseInvoiceService::class)->pay($invoice->fresh(), $this->treasury()->id, 1000);

        $this->get(route('admin.purchasing.suppliers.index', ['filter' => 'with_balance']))
            ->assertOk()->assertDontSee('بضاعة الصين');
    }
}
