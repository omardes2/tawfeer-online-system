<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الترحيل المزدوج لفاتورة الاستيراد — المرحلة ٢.
 *
 * المخزون يُدان بالتكلفة **الشاملة**، وذمّة المورد بسعرها **الحقيقي**، والفرق
 * يُقيَّد في «مصاريف استيراد مستحقة» — التزامٌ لشركة الشحن/المكتب لم تصل فاتورته.
 *
 * المثال: 200 قطعة بـ45 ¥، الرمبي 7.15 للدولار، الدولار 3.65، عمولة 5٪،
 * المتر المكعّب 180 $ وحجم الوحدة 0.012 م³.
 *   ذمّة المورد  4,594.40 ₪ · قيمة المخزون 6,400.94 ₪ · الفرق 1,806.54 ₪
 */
class ImportInvoicePostingTest extends TestCase
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
    }

    private function accrualCode(): string
    {
        return config('accounting.purchasing.import_accrual_account');
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    /** @param  array<int, array<string, mixed>>|null  $items */
    private function postInvoice(array $head = self::HEAD, ?array $items = null, array $extra = []): PurchaseInvoice
    {
        return $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()] + $head,
            $items ?? [array_merge([
                'variant_id' => $this->variant->id,
                'qty' => 200,
                'unit_price_foreign' => 45,
                'cbm_per_unit' => 0.012,
            ], $extra)],
        );
    }

    /** @return array<string, float> رصيد كل حساب في القيد (موجب مدين، سالب دائن). */
    private function entryLines(PurchaseInvoice $invoice): array
    {
        $entry = JournalEntry::with('lines.account')->findOrFail($invoice->journal_entry_id);

        return $entry->lines->mapWithKeys(fn ($l) => [
            $l->account->code => round((float) $l->debit - (float) $l->credit, 2),
        ])->all();
    }

    public function test_the_accrual_account_exists_in_the_chart(): void
    {
        $account = Account::where('code', $this->accrualCode())->first();

        $this->assertNotNull($account, 'حساب مصاريف الاستيراد المستحقة غير مزروع.');
        $this->assertSame('liability', $account->type);
        $this->assertTrue((bool) $account->is_postable);
    }

    public function test_inventory_is_debited_with_the_landed_cost_not_the_supplier_price(): void
    {
        $before = $this->balance(config('accounting.purchasing.inventory_account'));
        $invoice = $this->postInvoice();
        $moved = $this->balance(config('accounting.purchasing.inventory_account')) - $before;

        $this->assertEqualsWithDelta((float) $invoice->landed_subtotal, $moved, 0.02);
        $this->assertGreaterThan((float) $invoice->subtotal, $moved);
    }

    public function test_the_supplier_payable_keeps_the_real_price(): void
    {
        $invoice = $this->postInvoice();
        $lines = $this->entryLines($invoice);
        $payable = $invoice->supplier->glAccount()->first()?->code ?? config('accounting.purchasing.payable_account');

        // دائن ⇒ سالب. ذمّة المورد لا تحمل قرشًا من مصاريف الشحن.
        $this->assertEqualsWithDelta(-(float) $invoice->total, $lines[$payable], 0.02);
        $this->assertEqualsWithDelta(4594.40, (float) $invoice->subtotal, 0.05);
    }

    public function test_the_difference_is_credited_to_the_accrual_account(): void
    {
        $invoice = $this->postInvoice();
        $lines = $this->entryLines($invoice);

        $this->assertArrayHasKey($this->accrualCode(), $lines);
        $this->assertEqualsWithDelta(-$invoice->importDifference(), $lines[$this->accrualCode()], 0.02);
        $this->assertEqualsWithDelta(1806.54, $invoice->importDifference(), 0.05);
    }

    public function test_the_entry_balances(): void
    {
        $invoice = $this->postInvoice();
        $entry = JournalEntry::with('lines')->findOrFail($invoice->journal_entry_id);

        $this->assertEqualsWithDelta(
            (float) $entry->lines->sum('debit'),
            (float) $entry->lines->sum('credit'),
            0.01,
        );
        // مخزون + استحقاق دائن + ذمّة مورد = ثلاثة سطور (بلا ضريبة).
        $this->assertCount(3, $entry->lines);
    }

    public function test_it_balances_with_tax_too(): void
    {
        $invoice = $this->postInvoice(items: [[
            'variant_id' => $this->variant->id, 'qty' => 200,
            'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012, 'tax_rate' => 15,
        ]]);
        $entry = JournalEntry::with('lines')->findOrFail($invoice->journal_entry_id);

        $this->assertCount(4, $entry->lines);
        $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.01);
        $this->assertGreaterThan(0, (float) $invoice->tax_amount);
    }

    public function test_the_stock_enters_at_the_landed_unit_cost(): void
    {
        $invoice = $this->postInvoice();
        $warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->first();
        $stock = InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $warehouse->id)->first();

        // متوسط التكلفة هو أساسُ ربح ما يُباع لاحقًا — لذا يجب أن يحمل المصاريف.
        $this->assertEqualsWithDelta(32.0046, (float) $this->variant->fresh()->average_cost, 0.01);
        $this->assertEqualsWithDelta(200, (float) $stock->on_hand, 0.001);
        $this->assertEqualsWithDelta((float) $invoice->landed_subtotal, 200 * 32.0046, 0.5);
    }

    public function test_a_manual_cost_below_the_supplier_price_flips_the_accrual_to_debit(): void
    {
        // تقديرٌ أقلّ من سعر المورد يعني تخفيفَ حملٍ سابق — الاتجاه يُشتقّ من الإشارة.
        $invoice = $this->postInvoice(extra: ['landed_is_manual' => true, 'landed_unit_cost' => 20]);
        $lines = $this->entryLines($invoice);

        $this->assertLessThan(0, $invoice->importDifference());
        $this->assertGreaterThan(0, $lines[$this->accrualCode()]); // مدين
        $this->assertEqualsWithDelta(abs($invoice->importDifference()), $lines[$this->accrualCode()], 0.02);
    }

    public function test_no_accrual_line_when_the_landed_cost_equals_the_supplier_price(): void
    {
        // بلا عمولة ولا شحن لا فرق — والقيد يرفض سطرًا صفريًا أصلًا.
        $invoice = $this->postInvoice(['fx_rate_to_usd' => 7.15, 'usd_rate' => 3.65, 'currency' => 'CNY']);
        $entry = JournalEntry::with('lines.account')->findOrFail($invoice->journal_entry_id);

        $this->assertTrue($invoice->isImport());
        $this->assertEqualsWithDelta(0, $invoice->importDifference(), 0.01);
        $this->assertCount(2, $entry->lines);
        $this->assertNotContains($this->accrualCode(), $entry->lines->pluck('account.code')->all());
    }

    public function test_a_local_invoice_posts_exactly_as_before(): void
    {
        $before = $this->balance(config('accounting.purchasing.inventory_account'));
        $accrualBefore = $this->balance($this->accrualCode());

        $invoice = $this->postInvoice([], [[
            'variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 50, 'tax_rate' => 15,
        ]]);

        $this->assertFalse($invoice->isImport());
        $this->assertEqualsWithDelta(500, $this->balance(config('accounting.purchasing.inventory_account')) - $before, 0.01);
        $this->assertEqualsWithDelta(0, $this->balance($this->accrualCode()) - $accrualBefore, 0.01);
        $this->assertEqualsWithDelta(50, (float) $this->variant->fresh()->average_cost, 0.01);
    }

    public function test_reversing_the_invoice_clears_the_accrual(): void
    {
        $before = $this->balance($this->accrualCode());
        $invoice = $this->postInvoice();
        $this->assertNotEqualsWithDelta($before, $this->balance($this->accrualCode()), 0.01);

        $this->service->reverse($invoice->fresh());

        // العكس يعيد كل طرف لمكانه — بما فيه الحساب الوسيط.
        $this->assertEqualsWithDelta($before, $this->balance($this->accrualCode()), 0.01);
    }

    public function test_deleting_the_invoice_clears_the_accrual_and_the_stock(): void
    {
        $accrualBefore = $this->balance($this->accrualCode());
        $inventoryBefore = $this->balance(config('accounting.purchasing.inventory_account'));

        $invoice = $this->postInvoice();
        $this->service->deletePosted($invoice->fresh('items'));

        $this->assertEqualsWithDelta($accrualBefore, $this->balance($this->accrualCode()), 0.01);
        $this->assertEqualsWithDelta($inventoryBefore, $this->balance(config('accounting.purchasing.inventory_account')), 0.01);
    }

    public function test_editing_a_posted_invoice_recomputes_the_accrual(): void
    {
        $invoice = $this->postInvoice();
        $accrualAfterFirst = $this->balance($this->accrualCode());

        // مضاعفة تكلفة الشحن ⇒ مصاريف محمّلة أكبر ⇒ استحقاق أكبر.
        $this->service->updatePosted(
            $invoice->fresh('items'),
            ['cbm_rate_usd' => 360] + self::HEAD + ['supplier_id' => $this->supplier->id],
            [['variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012]],
        );
        $updated = $invoice->fresh();

        // رصيد الخصم يُقرأ دائنًا موجبًا — فالاستحقاق يساوي المصاريف المحمّلة.
        $this->assertEqualsWithDelta($updated->importDifference(), $this->balance($this->accrualCode()), 0.05);
        $this->assertGreaterThan($accrualAfterFirst, $this->balance($this->accrualCode()));
        $this->assertEqualsWithDelta((float) $updated->subtotal, 4594.40, 0.05); // ذمّة المورد لم تتحرّك
    }

    public function test_a_new_product_defined_on_an_import_invoice_takes_the_landed_cost(): void
    {
        $invoice = $this->postInvoice(items: [[
            'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012,
            'new_product_name' => 'شواية متنقلة', 'new_product_sell_price' => 90,
        ]]);
        $variant = $invoice->fresh('items')->items->first()->variant;

        $this->assertNotNull($variant);
        $this->assertEqualsWithDelta(32.0046, (float) $variant->cost_price, 0.01);
        $this->assertEqualsWithDelta(90, (float) $variant->retail_price, 0.01);
        // وحجم الوحدة يُحفظ في البطاقة فلا يُعاد قياسه في الشحنة القادمة.
        $this->assertEqualsWithDelta(0.012, (float) $variant->product->cbm, 0.0001);
    }
}
