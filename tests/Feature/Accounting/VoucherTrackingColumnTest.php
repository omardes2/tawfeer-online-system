<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/**
 * سندات القبض تحمل رقم التتبّع — على الشاشة وفي التصدير.
 *
 * ## لماذا
 *
 * سند تحصيل COD يُقيَّد على «ذمم شركات التوصيل» ويحمل **رقم الطلب** مرجعًا،
 * بينما فاتورة شركة التوصيل تُكتب بـ**رقم التتبّع**. فمطابقة الكشفين كانت تجري
 * بالمبلغ وحده — والمبالغ تتكرّر كثيرًا في مئات السطور.
 *
 * ## والتصدير يتبع الفلتر
 *
 * كان يقرأ التاريخين وحدهما ويتجاهل الحالة والبحث، فيُصدَّر ملفٌّ أوسع مما على
 * الشاشة: يُفلتر المستخدم «المُرحّلة» ثم يجد الملغاة في ملفّه فيبني عليها.
 */
class VoucherTrackingColumnTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Treasury $treasury;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($this->admin);

        $warehouse = Warehouse::firstOrFail();
        $product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 500, 'wholesale_price' => 300,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($product->defaultVariant, $warehouse, 100, 200);

        $this->order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'رنا واتس', 'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
        ], [[
            'variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 500,
        ]], (int) now()->year);

        $this->order->forceFill(['tracking_number' => '7441552'])->save();

        $this->treasury = Treasury::active()->firstOrFail();
    }

    /** سند تحصيل COD: مرجعُه رقم الطلب، وحسابه المقابل «ذمم شركات التوصيل». */
    private function codVoucher(bool $post = true, ?string $reference = null): FinancialVoucher
    {
        $service = app(VoucherService::class);

        $voucher = $service->create('receipt', [
            'treasury_id' => $this->treasury->id,
            'amount' => 500,
            'counter_account_id' => Account::where('code', '1050')->firstOrFail()->id,
            'reference' => $reference ?? $this->order->number,
            'description' => 'تحصيل COD من شركة التوصيل',
            'voucher_date' => now()->toDateString(),
        ]);

        return $post ? $service->post($service->approve($voucher)) : $voucher;
    }

    private function export(array $query = []): BinaryFileResponse
    {
        return $this->get(route('admin.accounting.vouchers.export', ['kind' => 'receipt'] + $query))
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

    // ────────── الشاشة ──────────

    /** **رقم التتبّع في عمودٍ على شاشة سندات القبض.** */
    public function test_the_index_shows_the_tracking_number(): void
    {
        $this->codVoucher();

        $this->get(route('admin.accounting.vouchers.index', ['kind' => 'receipt']))
            ->assertOk()
            ->assertSee('رقم التتبّع')
            ->assertSee('7441552');
    }

    /** وسندٌ بلا طلب يُعرض بشرطةٍ لا بفراغٍ يُوهم عطبًا. */
    public function test_a_voucher_without_an_order_renders_a_dash(): void
    {
        $service = app(VoucherService::class);
        $voucher = $service->create('receipt', [
            'treasury_id' => $this->treasury->id,
            'amount' => 75,
            'counter_account_id' => Account::where('code', '3010')->firstOrFail()->id,
            'description' => 'إيداع نقدي',
            'voucher_date' => now()->toDateString(),
        ]);
        $service->post($service->approve($voucher));

        $this->get(route('admin.accounting.vouchers.index', ['kind' => 'receipt']))
            ->assertOk()
            ->assertSee('—');
    }

    // ────────── التصدير ──────────

    /** **يُنزَّل ملفَّ xlsx** — لا CSV يُحوّل رقم التتبّع إلى صيغةٍ أسّية. */
    public function test_the_export_downloads_an_xlsx(): void
    {
        $this->codVoucher();

        $response = $this->export();

        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        $this->assertSame('PK', substr(file_get_contents($response->getFile()->getPathname()), 0, 2));
    }

    /** **ويحمل رقم التتبّع** مع بقيّة الأعمدة. */
    public function test_the_export_carries_the_tracking_number(): void
    {
        $this->codVoucher();

        $text = $this->sheetText($this->export());

        $this->assertStringContainsString('رقم التتبّع', $text);
        $this->assertStringContainsString('7441552', $text);
    }

    /** ورقم التتبّع نصٌّ لا رقم — وإلّا كسره Excel. */
    public function test_the_tracking_number_is_written_as_text(): void
    {
        $this->codVoucher();

        $reader = new Reader;
        $reader->open($this->export()->getFile()->getPathname());

        $found = false;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->toArray() as $cell) {
                    if ($cell === '7441552') {
                        $found = true;
                    }
                }
            }
            break;
        }
        $reader->close();

        $this->assertTrue($found, 'رقم التتبّع يجب أن يُكتب نصًّا لا رقمًا.');
    }

    // ────────── التصدير يتبع الفلتر ──────────

    /**
     * **فلتر الحالة يُطبَّق على الملفّ.**
     *
     * كان التصدير يقرأ التاريخين وحدهما، فيُفلتر المستخدم «المُرحّلة» ويجد
     * المسودّات في ملفّه.
     */
    public function test_the_export_honours_the_status_filter(): void
    {
        $posted = $this->codVoucher();
        $draft = $this->codVoucher(post: false);

        $text = $this->sheetText($this->export(['status' => 'posted']));

        $this->assertStringContainsString($posted->number, $text);
        $this->assertStringNotContainsString($draft->number, $text);
    }

    /** وفلتر البحث كذلك. */
    public function test_the_export_honours_the_search_filter(): void
    {
        $wanted = $this->codVoucher();
        $other = $this->codVoucher();

        $text = $this->sheetText($this->export(['search' => $wanted->number]));

        $this->assertStringContainsString($wanted->number, $text);
        $this->assertStringNotContainsString($other->number, $text);
    }

    /** والمدّة كما كانت. */
    public function test_the_export_honours_the_date_filter(): void
    {
        $this->codVoucher();

        $text = $this->sheetText($this->export([
            'from' => now()->addDay()->toDateString(),
            'to' => now()->addDays(2)->toDateString(),
        ]));

        $this->assertStringNotContainsString('7441552', $text);
    }

    /** والترويسة تقول أي فلترٍ أنتج الملفّ. */
    public function test_the_export_states_its_filters(): void
    {
        $this->codVoucher();

        $text = $this->sheetText($this->export(['status' => 'posted']));

        $this->assertStringContainsString('سند قبض', $text);
        $this->assertStringContainsString('مُرحّل', $text);
    }
}
