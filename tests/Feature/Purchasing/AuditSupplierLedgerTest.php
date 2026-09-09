<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مطابقة حساب المورد — أداة قراءةٍ تكشف أين تفترق المستندات عن الدفتر.
 *
 * ## لماذا
 *
 * بطاقات صفحة المورد تُبنى من ثلاثة مصادر: الفواتير، والسندات، ودفتر الأستاذ.
 * والفرق بينها يُعرض «تسويات» بلا تفصيل — فرقمٌ لا يُفسَّر يُقرأ خطأً أو يُتجاهل،
 * وكلاهما سيّئ في حسابٍ يُدفع منه مال.
 *
 * وأخطر ما يبتلعه ذلك الصمت: **سندٌ يحمل اسم المورد ولم يُقيَّد على حسابه
 * الفرعيّ**. يُعدّ في «المدفوعات» ولا يُنقص «الرصيد المتبقّي» — فيبدو المورد
 * مدينًا بما سُدِّد له فعلًا.
 */
class AuditSupplierLedgerTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $invoices;

    private Supplier $supplier;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->invoices = app(PurchaseInvoiceService::class);
        // عبر الخدمة: هي التي تفتح الحساب الفرعيّ، وبلا حسابٍ لا مطابقة أصلًا.
        $this->supplier = app(SupplierService::class)->create(['name' => 'بضاعة الصين']);
        $this->variant = ProductVariant::factory()->create();
    }

    private function invoice(float $cost = 250, float $qty = 4): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => $cost]],
        );
    }

    /** سند دفعٍ على حساب المورد الفرعيّ — الطريق الصحيح. */
    private function payOnSubAccount(float $amount): FinancialVoucher
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('payment', [
            'treasury_id' => Treasury::active()->firstOrFail()->id,
            'amount' => $amount,
            'counter_account_id' => $this->supplier->glAccount()->firstOrFail()->id,
            'supplier_id' => $this->supplier->id,
            'voucher_date' => now()->toDateString(),
        ]);

        return $vouchers->post($vouchers->approve($voucher));
    }

    /** وسندٌ باسم المورد على حساب الذمم الأمّ — الطريق الذي يُنتج الفرق. */
    private function payOnParentAccount(float $amount): FinancialVoucher
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('payment', [
            'treasury_id' => Treasury::active()->firstOrFail()->id,
            'amount' => $amount,
            'counter_account_id' => Account::where('code', '2010')->firstOrFail()->id,
            'supplier_id' => $this->supplier->id,
            'voucher_date' => now()->toDateString(),
        ]);

        return $vouchers->post($vouchers->approve($voucher));
    }

    /** **حسابٌ سليم يُبلَّغ مطابِقًا.** */
    public function test_a_healthy_account_reconciles(): void
    {
        $this->invoice();
        $this->payOnSubAccount(400);

        $this->artisan('purchasing:audit-supplier-ledger', ['supplier' => 'بضاعة الصين'])
            ->expectsOutputToContain('كل الحسابات مطابِقة')
            ->assertSuccessful();
    }

    /** **والسند الشارد يُسمّى بالاسم** — لا يُبتلَع في «تسويات». */
    public function test_a_stray_voucher_is_named(): void
    {
        $this->invoice();
        $stray = $this->payOnParentAccount(400);

        $this->artisan('purchasing:audit-supplier-ledger', ['supplier' => 'بضاعة الصين'])
            ->expectsOutputToContain($stray->number)
            ->expectsOutputToContain('لم تُقيَّد على حساب المورد الفرعيّ')
            ->assertSuccessful();
    }

    /** والفاتورة المعكوسة تُذكر في «خارج إجمالي المشتريات» لا تختفي. */
    public function test_a_reversed_invoice_is_listed_as_excluded(): void
    {
        $invoice = $this->invoice();
        $this->invoices->reverse($invoice->fresh('items'));

        $this->artisan('purchasing:audit-supplier-ledger', ['supplier' => 'بضاعة الصين'])
            ->expectsOutputToContain($invoice->number)
            ->expectsOutputToContain('فواتير خارج')
            ->assertSuccessful();
    }

    /** والرصيد الافتتاحي السالب يُوسَم بمعناه — «دفعنا مقدَّمًا» لا رقمًا صامتًا. */
    public function test_a_negative_opening_is_explained(): void
    {
        app(SupplierService::class)->syncOpeningBalance($this->supplier, -5000);

        $this->artisan('purchasing:audit-supplier-ledger', ['supplier' => 'بضاعة الصين'])
            ->expectsOutputToContain('دفعنا له مقدَّمًا')
            ->assertSuccessful();
    }

    /** والموجب كذلك. */
    public function test_a_positive_opening_is_explained(): void
    {
        app(SupplierService::class)->syncOpeningBalance($this->supplier, 5000);

        $this->artisan('purchasing:audit-supplier-ledger', ['supplier' => 'بضاعة الصين'])
            ->expectsOutputToContain('نحن مدينون له')
            ->assertSuccessful();
    }

    /** **ولا يكتب حرفًا** — وهو شرط أداةٍ تُشغَّل على بياناتٍ حيّة. */
    public function test_it_writes_nothing(): void
    {
        $this->invoice();
        $this->payOnParentAccount(400);
        app(SupplierService::class)->syncOpeningBalance($this->supplier, -5000);

        $before = $this->fingerprint();

        $this->artisan('purchasing:audit-supplier-ledger')->assertSuccessful();

        $this->assertSame($before, $this->fingerprint());
    }

    /** بصمة الجداول التي قد تتأثّر — تُقارَن قبل وبعد. */
    private function fingerprint(): string
    {
        $parts = [];

        foreach (['suppliers', 'purchase_invoices', 'financial_vouchers', 'journal_entries', 'journal_lines', 'inventory_stocks'] as $table) {
            $parts[] = $table.':'.DB::table($table)->count().':'
                .md5((string) DB::table($table)->orderBy('id')->get()->toJson());
        }

        return implode('|', $parts);
    }
}
