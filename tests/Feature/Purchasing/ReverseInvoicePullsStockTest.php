<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * عكس فاتورة الشراء يسحب البضاعة كما يعكس القيد.
 *
 * ## العطب
 *
 * كانت `reverse()` تعكس القيد المحاسبي وحده وتترك البضاعة في المستودع:
 *
 *     $reversal = $this->accounting->reverse($invoice->journalEntry);
 *     $invoice->update(['status' => 'reversed', ...]);
 *
 * فتختفي قيمة المشتريات وذمّة المورد من الدفاتر ويبقى الرصيد الموجب — بضاعةٌ
 * بلا تكلفة مقابلة، وذمّةٌ تقول إنك لا تدين لموردك وأنت مدين بثمن ما في مخزنك.
 *
 * وكان الزرّ مُوصًى به: رسالة رفض التعديل تقول «أو اعكس الفاتورة بدل تعديلها» —
 * تدلّ المستخدم على الطريق الذي يُفسد الدفتر، في اللحظة التي يكون فيها عالقًا
 * ومستعدًّا لتجريب أي مخرج.
 *
 * ونصفُ عكسٍ أسوأ من لا عكس: لا يظهر أثره إلّا في جردٍ أو ميزانٍ بعد شهور.
 */
class ReverseInvoicePullsStockTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    private Supplier $supplier;

    private ProductVariant $variant;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(PurchaseInvoiceService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->firstOrFail();
    }

    private function postedInvoice(float $qty = 100, float $cost = 35): PurchaseInvoice
    {
        return $this->service->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->variant->id, 'qty' => $qty, 'unit_cost' => $cost]],
        );
    }

    private function onHand(): float
    {
        return (float) InventoryStock::where('variant_id', $this->variant->id)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    /** رصيد حساب من القيود المُرحّلة (مدين − دائن). */
    private function balance(string $code): float
    {
        return (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.account_id', Account::where('code', $code)->value('id'))
            ->where('journal_entries.status', 'posted')
            ->sum(DB::raw('journal_lines.debit - journal_lines.credit'));
    }

    /** **العكس يسحب البضاعة** — لا تبقى في المخزن بلا تكلفة. */
    public function test_reversing_pulls_the_goods_out(): void
    {
        $invoice = $this->postedInvoice(100);
        $this->assertEqualsWithDelta(100, $this->onHand(), 0.001);

        $this->service->reverse($invoice->fresh('items'));

        $this->assertEqualsWithDelta(0, $this->onHand(), 0.001);
    }

    /** والقيد يُعكس كما كان — الإصلاح يضيف ولا يُلغي. */
    public function test_reversing_still_reverses_the_entry(): void
    {
        $invoice = $this->postedInvoice(100);

        $reversed = $this->service->reverse($invoice->fresh('items'));

        $this->assertSame('reversed', $reversed->status);
        $this->assertNotNull($reversed->reversal_entry_id);
    }

    /**
     * **والدفتر والمخزن يتفقان بعده.**
     *
     * وهو جوهر العطب: كان المخزون يبقى موجبًا وذمّة المورد صفرًا — بضاعة بلا
     * دائن. الآن يعودان معًا إلى الصفر.
     */
    public function test_the_ledger_and_the_warehouse_agree_afterwards(): void
    {
        $invoice = $this->postedInvoice(100, 35);

        $this->service->reverse($invoice->fresh('items'));

        $this->assertEqualsWithDelta(0, $this->onHand(), 0.001);
        $this->assertEqualsWithDelta(0, $this->balance('1200'), 0.01);  // المخزون
        $this->assertEqualsWithDelta(0, $this->balance('2010'), 0.01);  // ذمم الموردين
    }

    /**
     * **وإن لم تعد البضاعة متاحة يُمنع العكس بتمامه** — لا يُنفَّذ نصفًا.
     *
     * السحب يسبق عكس القيد داخل المعاملة، فرميُه يُسقطها قبل أن يُمسّ الدفتر.
     */
    public function test_reversing_is_refused_when_the_goods_left(): void
    {
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);

        $this->expectException(ValidationException::class);
        $this->service->reverse($invoice->fresh('items'));
    }

    /** والرفض لا يترك أثرًا: لا حالة تغيّرت ولا قيد عُكس ولا رصيد تحرّك. */
    public function test_the_refusal_leaves_nothing_behind(): void
    {
        $invoice = $this->postedInvoice(100);
        app(InventoryService::class)->issue($this->variant, $this->warehouse, 60);

        $stockBefore = $this->onHand();
        $payablesBefore = $this->balance('2010');

        try {
            $this->service->reverse($invoice->fresh('items'));
            $this->fail('كان يجب رفض العكس.');
        } catch (ValidationException) {
            // متوقَّع.
        }

        $invoice->refresh();

        $this->assertSame('posted', $invoice->status);
        $this->assertNull($invoice->reversal_entry_id);
        $this->assertEqualsWithDelta($stockBefore, $this->onHand(), 0.001);
        $this->assertEqualsWithDelta($payablesBefore, $this->balance('2010'), 0.01);
    }

    /** وفاتورة المصاريف لا بضاعة لها — تُعكس بلا حارس. */
    public function test_an_expense_invoice_reverses_freely(): void
    {
        $expense = $this->service->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'kind' => 'expenses',
        ], [['description' => 'أجور تخليص', 'qty' => 1, 'unit_cost' => 500]]);

        $this->assertSame('reversed', $this->service->reverse($expense->fresh('items'))->status);
    }
}
