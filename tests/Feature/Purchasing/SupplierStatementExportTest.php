<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * تصدير كشف حساب المورد إلى Excel.
 *
 * ## لماذا xlsx لا CSV
 *
 * الكشف يحمل أرقامًا طويلة وعملاتٍ ثلاثًا وعربيةً من اليمين. وCSV يُسلّم Excel
 * تخمينَ الترميز والفواصل، فتُقرأ العربية طلاسمَ وتُحوَّل الأرقام الطويلة إلى
 * صيغةٍ أسّية.
 *
 * ## والعملة عمودٌ مستقلّ
 *
 * الكشف يخلط ¥ و$ و₪. ورمزٌ ملصوقٌ بالرقم يجعل الخلية نصًّا لا يُجمَع ولا
 * يُفرَز — وهو أوّل ما يفعله من يفتح الملفّ.
 *
 * ## والرصيد يُقرأ من الكشف نفسه
 *
 * لا يُعاد حسابه في المُصدِّر: ملفٌّ يخالف الشاشة في رقمٍ واحد يُفقد الاثنين
 * ثقتَهما، ولا يُعرف أيّهما يُصدَّق.
 */
class SupplierStatementExportTest extends TestCase
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
        $this->supplier = app(SupplierService::class)->create(['name' => 'بضاعة الصين']);
        $this->variant = ProductVariant::factory()->create();
    }

    private function importInvoice(): PurchaseInvoice
    {
        return $this->invoices->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'currency' => 'CNY',
            'fx_rate_to_usd' => 7.0,
            'usd_rate' => 3.7,
        ], [[
            'variant_id' => $this->variant->id, 'qty' => 100,
            'unit_price_foreign' => 10, 'cbm_per_unit' => 0,
        ]]);
    }

    private function export(): BinaryFileResponse
    {
        return $this->get(route('admin.purchasing.suppliers.statement.export', $this->supplier))
            ->assertOk()->baseResponse;
    }

    private function sheetText(BinaryFileResponse $response): string
    {
        $reader = new Reader;
        $reader->open($response->getFile()->getPathname());

        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = implode('|', array_map(
                    static fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : (string) $c,
                    $row->toArray(),
                ));
            }
            break;
        }
        $reader->close();

        return implode("\n", $rows);
    }

    /** **يُنزَّل ملفَّ xlsx** لا CSV. */
    public function test_it_downloads_an_xlsx(): void
    {
        $this->importInvoice();

        $response = $this->export();

        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertSame('PK', substr(file_get_contents($response->getFile()->getPathname()), 0, 2));
    }

    /** **ويحمل سطور الكشف وأعمدته.** */
    public function test_it_carries_the_statement_rows(): void
    {
        $invoice = $this->importInvoice();

        $text = $this->sheetText($this->export());

        $this->assertStringContainsString($invoice->number, $text);
        $this->assertStringContainsString(__('الرصيد'), $text);
        $this->assertStringContainsString(__('قيمة الفاتورة بعملتها'), $text);
    }

    /** **وقيمة الفاتورة بعملتها ورمزُها في عمودين** — فتُجمع وتُفرَز. */
    public function test_the_currency_is_its_own_column(): void
    {
        $this->importInvoice();

        $reader = new Reader;
        $reader->open($this->export()->getFile()->getPathname());

        $numeric = false;
        $symbol = false;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();
                // القيمة رقمٌ خالص، والرمز خليةٌ منفصلة — لا «1000 ¥» في واحدة.
                if (in_array(1000.0, $cells, false) && ! in_array('1,000.00 ¥', $cells, true)) {
                    $numeric = true;
                }
                if (in_array('¥', $cells, true)) {
                    $symbol = true;
                }
            }
            break;
        }
        $reader->close();

        $this->assertTrue($numeric, 'قيمة الفاتورة يجب أن تُكتب رقمًا خالصًا.');
        $this->assertTrue($symbol, 'الرمز يجب أن يكون في عمودٍ مستقلّ.');
    }

    /** والترويسة تعرّف الكشف — ملفٌّ بلا اسم مورده لا يصلح مستندًا. */
    public function test_the_header_names_the_supplier(): void
    {
        $this->importInvoice();

        $text = $this->sheetText($this->export());

        $this->assertStringContainsString('بضاعة الصين', $text);
        $this->assertStringContainsString($this->supplier->code, $text);
    }

    /** **والرصيد في الملفّ هو رصيد الشاشة** — لا يُعاد حسابه فيفترقان. */
    public function test_the_balance_matches_the_screen(): void
    {
        $this->importInvoice();

        $onScreen = $this->get(route('admin.purchasing.suppliers.show', $this->supplier))
            ->assertOk()->viewData('statement')->last()['balance'];

        $this->assertStringContainsString(
            (string) round((float) $onScreen, 2),
            $this->sheetText($this->export()),
        );
    }

    /** وسطور الدفعات والافتتاحي بلا قيمةٍ أجنبية — فراغٌ لا صفر. */
    public function test_non_invoice_rows_carry_no_foreign_value(): void
    {
        app(SupplierService::class)->syncOpeningBalance($this->supplier, 5000);
        $this->importInvoice();

        $text = $this->sheetText($this->export());

        $this->assertStringContainsString(__('رصيد افتتاحي'), $text);
        $this->assertStringNotContainsString('5000|¥', $text);
    }

    /** ومورّدٌ بلا حركات يُصدَّر بترويسته بلا سقوط. */
    public function test_an_empty_statement_still_exports(): void
    {
        $this->assertStringContainsString('بضاعة الصين', $this->sheetText($this->export()));
    }
}
