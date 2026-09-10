<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * دمج مورّدٍ مكرّر في آخر — بلا مساس بقيدٍ مُرحّل.
 *
 * ## المشكلة
 *
 * المورد الواحد يُدخَل مرّتين بفارق حرف («بضاعه» و«بضاعة»)، فيُفتح له حسابان
 * فرعيّان تتوزّع حركاته بينهما، ويظهر في ميزان المراجعة مرّتين — ولا يُعرف كم
 * يُدان له إلّا بجمعٍ يدوي.
 *
 * ## ولماذا لا تُنقل القيود
 *
 * تحويل `journal_lines.account_id` من حساب إلى حساب إعادةُ كتابةٍ لدفترٍ مُرحّل:
 * تُفسد أرصدة الفترات المُقفلة أثرًا رجعيًّا، وتجعل ميزانًا طُبع أمسِ يخالف نفسه
 * اليوم بلا قيدٍ يفسّر الفرق. وBR-ACC-09 يمنعها.
 *
 * فالرصيد ينتقل بقيد إعادة تصنيف، والمستندات تُسنَد، والحساب القديم يُعطَّل ولا
 * يُحذف — وكشف الهدف يقرأ الحسابين معًا فيُرى التاريخ كاملًا.
 */
class MergeSuppliersTest extends TestCase
{
    use RefreshDatabase;

    private SupplierService $suppliers;

    private PurchaseInvoiceService $invoices;

    private Supplier $source;

    private Supplier $target;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->suppliers = app(SupplierService::class);
        $this->invoices = app(PurchaseInvoiceService::class);

        // الاسمان بفارق حرفٍ واحد — الحالة الحقيقية بعينها.
        $this->source = $this->suppliers->create(['name' => 'بضاعه الصين']);
        $this->target = $this->suppliers->create(['name' => 'بضاعة الصين']);
        $this->variant = ProductVariant::factory()->create();
    }

    private function invoiceFor(Supplier $supplier, float $cost = 250, float $qty = 4): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            ['supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => $cost]],
        );
    }

    private function accountBalance(Supplier $supplier): float
    {
        return round(app(AccountingService::class)->accountBalance(
            $supplier->glAccount()->firstOrFail(),
        ), 2);
    }

    // ────────── الرصيد ──────────

    /** **رصيد المصدر ينتقل كاملًا للهدف.** */
    public function test_the_balance_moves_to_the_target(): void
    {
        $this->invoiceFor($this->source, 250, 4);   // 1,000
        $this->invoiceFor($this->target, 500, 1);   //   500

        $this->suppliers->merge($this->source, $this->target);

        $this->assertEqualsWithDelta(1500, $this->accountBalance($this->target->fresh()), 0.01);
    }

    /** **وحساب المصدر يُصفَّر** — فلا يظهر في ميزان المراجعة برصيد. */
    public function test_the_source_account_is_zeroed(): void
    {
        $this->invoiceFor($this->source, 250, 4);

        $this->suppliers->merge($this->source, $this->target);

        $this->assertEqualsWithDelta(0, $this->accountBalance($this->source->fresh()), 0.01);
    }

    /** **ولا يُعدَّل ولا يُحذف أيّ سطر قيد** — الدفتر يكبر ولا يُعاد كتابته. */
    public function test_no_posted_line_is_rewritten(): void
    {
        $this->invoiceFor($this->source, 250, 4);
        $this->invoiceFor($this->target, 500, 1);

        $sourceAccount = $this->source->glAccount()->firstOrFail();
        $before = JournalLine::where('account_id', $sourceAccount->id)
            ->orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->toArray();

        $this->suppliers->merge($this->source, $this->target);

        $after = JournalLine::whereIn('id', array_column($before, 'id'))
            ->orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->toArray();

        $this->assertSame($before, $after);
    }

    /** والرصيد المدين (دفعنا مقدَّمًا) ينتقل بالاتجاه المعكوس. */
    public function test_a_debit_balance_moves_the_other_way(): void
    {
        $this->suppliers->syncOpeningBalance($this->source, -3000);

        $this->suppliers->merge($this->source, $this->target);

        $this->assertEqualsWithDelta(0, $this->accountBalance($this->source->fresh()), 0.01);
        $this->assertEqualsWithDelta(-3000, $this->accountBalance($this->target->fresh()), 0.01);
    }

    /** ورصيدٌ صفر لا يحتاج قيدًا — ومحرّك القيد يرفض سطرًا صفريًّا. */
    public function test_a_zero_balance_posts_no_entry(): void
    {
        $result = $this->suppliers->merge($this->source, $this->target);

        $this->assertNull($result['entry']);
    }

    // ────────── المستندات ──────────

    /** **المستندات تُسنَد للهدف** — فالفواتير تُقرأ تحت مورّدٍ واحد. */
    public function test_documents_are_reassigned(): void
    {
        $invoice = $this->invoiceFor($this->source);

        $this->suppliers->merge($this->source, $this->target);

        $this->assertSame($this->target->id, $invoice->fresh()->supplier_id);
    }

    // ────────── حالة المصدر ──────────

    /** **حساب المصدر يُعطَّل ولا يُحذف** — يحمل قيودًا مُرحّلة. */
    public function test_the_source_account_is_deactivated_not_deleted(): void
    {
        $accountId = $this->source->glAccount()->value('id');

        $this->suppliers->merge($this->source, $this->target);

        $account = Account::find($accountId);

        $this->assertNotNull($account, 'حساب المصدر يجب أن يبقى في دليل الحسابات.');
        $this->assertFalse((bool) $account->is_active);
    }

    /** والمصدر يُحذف ناعمًا ويُشار به إلى من دُمج فيه. */
    public function test_the_source_is_soft_deleted_and_points_at_the_target(): void
    {
        $this->suppliers->merge($this->source, $this->target);

        $source = Supplier::withTrashed()->find($this->source->id);

        $this->assertTrue($source->trashed());
        $this->assertSame($this->target->id, $source->merged_into_id);
    }

    /** وسلسلة الدمج تُسطَّح: من دُمج في المصدر يُدمج في الهدف مباشرةً. */
    public function test_the_merge_chain_is_flattened(): void
    {
        $older = $this->suppliers->create(['name' => 'بضاعة الصين القديمة']);
        $this->suppliers->merge($older, $this->source);

        $this->suppliers->merge($this->source, $this->target);

        $this->assertSame(
            $this->target->id,
            Supplier::withTrashed()->find($older->id)->merged_into_id,
        );
    }

    // ────────── الكشف ──────────

    /** **وكشف الهدف يعرض حركات الحسابين** — التاريخ كاملًا في مكانٍ واحد. */
    public function test_the_targets_statement_shows_both_histories(): void
    {
        $fromSource = $this->invoiceFor($this->source, 250, 4);
        $fromTarget = $this->invoiceFor($this->target, 500, 1);

        $this->suppliers->merge($this->source, $this->target);

        $this->get(route('admin.purchasing.suppliers.show', $this->target))
            ->assertOk()
            ->assertSee($fromSource->number)
            ->assertSee($fromTarget->number);
    }

    /** والرصيد الظاهر بعد الدمج هو المجموع — لا يُحسب القيد الناقل مرّتين. */
    public function test_the_statement_balance_is_not_double_counted(): void
    {
        $this->invoiceFor($this->source, 250, 4);   // 1,000
        $this->invoiceFor($this->target, 500, 1);   //   500

        $this->suppliers->merge($this->source, $this->target);

        $statement = $this->get(route('admin.purchasing.suppliers.show', $this->target))
            ->assertOk()->viewData('statement');

        $this->assertEqualsWithDelta(1500, (float) $statement->last()['balance'], 0.01);
    }

    // ────────── الحراسة ──────────

    /** المورد لا يُدمج في نفسه. */
    public function test_a_supplier_cannot_merge_into_itself(): void
    {
        $this->expectException(ValidationException::class);
        $this->suppliers->merge($this->source, $this->source);
    }

    /** والمدموج مسبقًا لا يُدمج ثانيةً. */
    public function test_an_already_merged_supplier_is_refused(): void
    {
        $this->suppliers->merge($this->source, $this->target);

        $third = $this->suppliers->create(['name' => 'مورد ثالث']);

        $this->expectException(ValidationException::class);
        $this->suppliers->merge(Supplier::withTrashed()->find($this->source->id), $third);
    }

    // ────────── الأمر ──────────

    /** الأمر يعرض ولا يكتب بلا `--apply`. */
    public function test_the_command_is_read_only_without_apply(): void
    {
        $this->invoiceFor($this->source);

        $this->artisan('purchasing:merge-suppliers', [
            'source' => (string) $this->source->id,
            'target' => (string) $this->target->id,
        ])
            ->expectsOutputToContain('عرضٌ فقط')
            ->assertSuccessful();

        $this->assertFalse(Supplier::withTrashed()->find($this->source->id)->trashed());
    }

    /** ويرفض الاسم الملتبس بدل أن يختار أحدهما آليًّا. */
    public function test_an_ambiguous_name_is_refused(): void
    {
        $this->artisan('purchasing:merge-suppliers', [
            'source' => 'الصين',
            'target' => (string) $this->target->id,
        ])
            ->expectsOutputToContain('حدّد بالرمز أو المعرّف')
            ->assertFailed();
    }
}
