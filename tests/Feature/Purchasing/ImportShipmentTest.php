<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * الشحنة (الكونتينر) وفاتورة المصاريف وإغلاق فرق التقدير — المرحلة ٣.
 *
 * المسار الكامل: فاتورة بضاعة تُحمّل الحساب الوسيط بتقديرها (1,806.54 ₪ في
 * مثالنا)، ثم تصل فاتورة الشحن والجمارك فتُطفئه بالفعلي، وما يتبقّى فرقُ تقدير
 * يُقفل عند إغلاق الشحنة.
 */
class ImportShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = [
        'fx_rate_to_usd' => 7.15,
        'usd_rate' => 3.65,
        'commission_rate' => 5,
        'cbm_rate_usd' => 180,
        'currency' => 'CNY',
    ];

    private PurchaseInvoiceService $invoices;

    private ImportShipmentService $service;

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

        $this->invoices = app(PurchaseInvoiceService::class);
        $this->service = app(ImportShipmentService::class);
        $this->accounting = app(AccountingService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    private function accrualCode(): string
    {
        return config('accounting.purchasing.import_accrual_account');
    }

    private function varianceCode(): string
    {
        return config('accounting.purchasing.import_variance_account');
    }

    private function shipment(): ImportShipment
    {
        return $this->service->create(['reference' => 'MSKU1234567', 'supplier_id' => $this->supplier->id]);
    }

    /** فاتورة البضاعة المرجعية: تُحمّل 1,806.54 ₪ على الحساب الوسيط. */
    private function goodsInvoice(ImportShipment $shipment): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'import_shipment_id' => $shipment->id,
                'kind' => 'goods',
            ] + self::HEAD,
            [['variant_id' => $this->variant->id, 'qty' => 200, 'unit_price_foreign' => 45, 'cbm_per_unit' => 0.012]],
        );
    }

    /** فاتورة مصاريف بالدولار (سعر الصرف للدولار = 1). */
    private function expenseInvoice(ImportShipment $shipment, float $usdAmount): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'import_shipment_id' => $shipment->id,
                'kind' => 'expenses',
                'currency' => 'USD',
                'fx_rate_to_usd' => 1,
                'usd_rate' => 3.65,
            ],
            [['description' => 'شحن بحري وجمارك', 'qty' => 1, 'unit_price_foreign' => $usdAmount, 'unit_cost' => 0]],
        );
    }

    public function test_a_shipment_gets_a_sequential_number(): void
    {
        $shipment = $this->shipment();

        $this->assertStringStartsWith('CNTR-'.now()->year.'-', $shipment->number);
        $this->assertTrue($shipment->isOpen());
    }

    public function test_the_variance_account_exists_in_the_chart(): void
    {
        $account = Account::where('code', $this->varianceCode())->first();

        $this->assertNotNull($account, 'حساب فروق تقدير الاستيراد غير مزروع.');
        $this->assertSame('expense', $account->type);
        $this->assertTrue((bool) $account->is_postable);
    }

    public function test_an_expense_invoice_debits_the_accrual_and_moves_no_stock(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $accrualAfterGoods = $this->balance($this->accrualCode());
        $stockBefore = (float) InventoryStock::where('variant_id', $this->variant->id)->sum('on_hand');

        $expense = $this->expenseInvoice($shipment, 400); // 1,460 ₪

        $this->assertTrue($expense->isExpenseInvoice());
        $this->assertEqualsWithDelta(1460, (float) $expense->subtotal, 0.05);
        // الحساب الوسيط يُطفأ بمقدار الفاتورة (رصيد الخصم يُقرأ دائنًا موجبًا).
        $this->assertEqualsWithDelta($accrualAfterGoods - 1460, $this->balance($this->accrualCode()), 0.05);
        // ولا بضاعة تدخل.
        $this->assertEqualsWithDelta($stockBefore, (float) InventoryStock::where('variant_id', $this->variant->id)->sum('on_hand'), 0.001);
    }

    public function test_an_expense_invoice_never_touches_the_inventory_account(): void
    {
        $shipment = $this->shipment();
        $before = $this->balance(config('accounting.purchasing.inventory_account'));

        $this->expenseInvoice($shipment, 400);

        $this->assertEqualsWithDelta($before, $this->balance(config('accounting.purchasing.inventory_account')), 0.01);
    }

    public function test_an_expense_invoice_without_a_shipment_falls_back_to_goods(): void
    {
        // بلا شحنة لا يُعرف أيّ تقدير تُطفئ — فلا تُحمَّل على الحساب الوسيط بلا مرجع.
        $invoice = $this->invoices->create(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString(), 'kind' => 'expenses'],
            [['description' => 'شحن', 'qty' => 1, 'unit_cost' => 500]],
        );

        $this->assertFalse($invoice->isExpenseInvoice());
        $this->assertSame(PurchaseInvoice::KIND_GOODS, $invoice->kind);
    }

    public function test_the_summary_reports_the_estimate_the_actual_and_the_variance(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 400); // 1,460 ₪ فعلي مقابل 1,806.54 مقدَّر

        $summary = $this->service->summary($shipment->fresh());

        $this->assertEqualsWithDelta(1806.54, $summary['accrued'], 0.05);
        $this->assertEqualsWithDelta(1460, $summary['actual'], 0.05);
        $this->assertEqualsWithDelta(346.54, $summary['variance'], 0.05);
        $this->assertSame(1, $summary['goods_count']);
        $this->assertSame(1, $summary['expenses_count']);
        $this->assertEqualsWithDelta(200, $summary['received_qty'], 0.001);
        $this->assertTrue($summary['over_tolerance']); // 19٪ — فوق حدّ التسامح
    }

    public function test_the_sold_ratio_tracks_what_left_the_warehouse(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);

        $this->assertEqualsWithDelta(0, $this->service->summary($shipment->fresh())['sold_ratio'], 0.1);

        // خروج نصف الكمية ⇒ 50٪ تقديريًا.
        InventoryStock::where('variant_id', $this->variant->id)->update(['on_hand' => 100]);

        $this->assertEqualsWithDelta(50, $this->service->summary($shipment->fresh())['sold_ratio'], 0.1);
    }

    public function test_closing_moves_the_residual_to_the_variance_account(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 400);

        $this->service->close($shipment->fresh());
        $closed = $shipment->fresh();

        $this->assertSame('closed', $closed->status);
        $this->assertEqualsWithDelta(346.54, (float) $closed->variance_amount, 0.05);
        $this->assertNotNull($closed->variance_entry_id);
        // الحساب الوسيط عاد للصفر، والفرق استقرّ في حساب النتيجة.
        $this->assertEqualsWithDelta(0, $this->balance($this->accrualCode()), 0.02);
        $this->assertEqualsWithDelta(-346.54, $this->balance($this->varianceCode()), 0.05);
    }

    public function test_an_under_estimate_debits_the_variance_account(): void
    {
        // فواتير فعلية أعلى من التقدير ⇒ خسارة تقدير تُسجَّل مصروفًا.
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 600); // 2,190 ₪ مقابل 1,806.54 مقدَّر

        $this->service->close($shipment->fresh());

        $this->assertLessThan(0, (float) $shipment->fresh()->variance_amount);
        $this->assertEqualsWithDelta(0, $this->balance($this->accrualCode()), 0.02);
        $this->assertEqualsWithDelta(383.46, $this->balance($this->varianceCode()), 0.05); // مدين
    }

    public function test_an_exact_estimate_closes_without_an_entry(): void
    {
        $shipment = $this->shipment();
        $goods = $this->goodsInvoice($shipment);
        // فاتورة مصاريف تساوي التقدير بالضبط ⇒ لا فرق ⇒ لا قيد بلا أثر.
        $this->expenseInvoice($shipment, round($goods->importDifference() / 3.65, 4));

        $this->service->close($shipment->fresh());
        $closed = $shipment->fresh();

        $this->assertSame('closed', $closed->status);
        $this->assertNull($closed->variance_entry_id);
        $this->assertEqualsWithDelta(0, (float) $closed->variance_amount, 0.01);
    }

    public function test_closing_a_shipment_without_posted_invoices_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->close($this->shipment());
    }

    public function test_a_closed_shipment_cannot_be_closed_or_edited_again(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->service->close($shipment->fresh());

        $this->expectException(ValidationException::class);
        $this->service->close($shipment->fresh());
    }

    public function test_reopening_reverses_the_variance_entry(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 400);
        $this->service->close($shipment->fresh());

        $this->service->reopen($shipment->fresh());
        $reopened = $shipment->fresh();

        $this->assertTrue($reopened->isOpen());
        $this->assertNull($reopened->variance_entry_id);
        $this->assertEqualsWithDelta(0, (float) $reopened->variance_amount, 0.01);
        // الحساب الوسيط عاد لرصيده قبل الإغلاق، وحساب الفروق تصفّر.
        $this->assertEqualsWithDelta(346.54, $this->balance($this->accrualCode()), 0.05);
        $this->assertEqualsWithDelta(0, $this->balance($this->varianceCode()), 0.02);
    }

    public function test_reclosing_after_a_reopen_posts_a_fresh_entry(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 400);
        $this->service->close($shipment->fresh());
        $firstEntryId = $shipment->fresh()->variance_entry_id;

        $this->service->reopen($shipment->fresh());
        $this->service->close($shipment->fresh());

        $this->assertNotSame($firstEntryId, $shipment->fresh()->variance_entry_id);
        $this->assertEqualsWithDelta(0, $this->balance($this->accrualCode()), 0.02);
    }

    public function test_a_shipment_with_invoices_cannot_be_deleted(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);

        $this->expectException(ValidationException::class);
        $this->service->delete($shipment->fresh());
    }

    public function test_the_index_lists_shipments_and_the_pending_balance(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);

        $this->get(route('admin.purchasing.shipments.index'))
            ->assertOk()
            ->assertSee($shipment->number)
            ->assertSee(__('رصيد معلّق (مصاريف لم تصل فواتيرها)'), false);
    }

    public function test_the_show_page_reveals_the_close_screen(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);
        $this->expenseInvoice($shipment, 400);

        $this->get(route('admin.purchasing.shipments.show', $shipment))
            ->assertOk()
            ->assertSee(__('التقدير المحمّل على البضاعة'), false)
            ->assertSee(__('الفواتير الفعلية'), false)
            ->assertSee(__('إغلاق الشحنة وترحيل الفرق'), false);
    }

    public function test_closing_through_the_web_route_needs_the_close_permission(): void
    {
        $shipment = $this->shipment();
        $this->goodsInvoice($shipment);

        // المستودع يرى الشحنات ولا يغلقها — الإغلاق قيدُ نتيجة.
        $warehouse = User::factory()->create(['branch_id' => Branch::default()->id]);
        $warehouse->assignRole('warehouse');

        $this->actingAs($warehouse)
            ->post(route('admin.purchasing.shipments.close', $shipment))
            ->assertForbidden();

        $this->assertTrue($shipment->fresh()->isOpen());
    }

    public function test_an_expense_invoice_requires_a_shipment_in_the_form(): void
    {
        $this->from(route('admin.purchasing.invoices.create'))
            ->post(route('admin.purchasing.invoices.store'), [
                'supplier_id' => $this->supplier->id,
                'invoice_date' => now()->toDateString(),
                'kind' => 'expenses',
                'items' => [['description' => 'شحن', 'qty' => 1, 'unit_cost' => 500]],
            ])
            ->assertSessionHasErrors('import_shipment_id');
    }
}
