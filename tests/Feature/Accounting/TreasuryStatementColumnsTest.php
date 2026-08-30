<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Accounting\Services\VoucherService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * كشف الخزينة يحمل **رقم التتبّع واسم الزبون**.
 *
 * سطرُ تحصيل COD كان يقول رقم القيد والمبلغ ورقم الطلب. وفاتورة شركة التوصيل
 * تُكتب بـ**رقم التتبّع** لا برقم الطلب، فمطابقتُها كانت تجري بالمبلغ وحده —
 * والمبالغ تتكرّر كثيرًا في مئات السطور.
 *
 * واسمُ الزبون كان ذيلًا داخل البيان لا عمودًا: لا يُفرَز ولا يُقرأ سريعًا.
 */
class TreasuryStatementColumnsTest extends TestCase
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
            'customer_name' => 'رنا واتس',
            'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
            'shipping_total' => 63,
        ], [[
            'variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 500,
        ]], (int) now()->year);

        $this->order->forceFill(['tracking_number' => '7441552'])->save();

        $this->treasury = Treasury::active()->firstOrFail();

        // سند قبضٍ مُرحَّل يحمل رقم الطلب مرجعًا — كما يفعل تحصيل COD.
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('receipt', [
            'treasury_id' => $this->treasury->id,
            'amount' => 500,
            'counter_account_id' => Account::where('code', '1050')->firstOrFail()->id,
            'reference' => $this->order->number,
            'description' => 'تحصيل COD من شركة التوصيل — طلب '.$this->order->number,
            'voucher_date' => now()->toDateString(),
        ]);
        $vouchers->post($vouchers->approve($voucher));
    }

    // ────────── شاشة الخزينة ──────────

    /** **رقم التتبّع يظهر في حركة الخزينة.** */
    public function test_the_treasury_page_shows_the_tracking_number(): void
    {
        $this->get(route('admin.accounting.cashboxes.show', $this->treasury))
            ->assertOk()
            ->assertSee('رقم التتبّع')
            ->assertSee('7441552');
    }

    /** واسم الزبون في عمودٍ مستقلّ. */
    public function test_the_treasury_page_shows_the_customer_column(): void
    {
        $this->get(route('admin.accounting.cashboxes.show', $this->treasury))
            ->assertOk()
            ->assertSee('الزبون')
            ->assertSee('رنا واتس');
    }

    // ────────── كشف الحساب المفصّل ──────────

    /** والكشف المفصّل يحملهما كذلك — هو ما يُطبع ويُطابَق عليه. */
    public function test_the_detailed_statement_shows_both(): void
    {
        $this->get(route('admin.accounting.finance_reports.treasury_statement', $this->treasury))
            ->assertOk()
            ->assertSee('7441552')
            ->assertSee('رنا واتس');
    }

    // ────────── الحدود ──────────

    /**
     * **حركةٌ بلا طلب لا تكسر الجدول.**
     *
     * التحويلات وقيود التسوية لا مرجعَ لها، فتُعرض بشرطةٍ لا بفراغٍ يُوهم عطبًا.
     */
    public function test_a_movement_without_an_order_renders_a_dash(): void
    {
        $vouchers = app(VoucherService::class);
        $voucher = $vouchers->create('receipt', [
            'treasury_id' => $this->treasury->id,
            'amount' => 75,
            'counter_account_id' => Account::where('code', '3010')->firstOrFail()->id,
            'description' => 'إيداع نقدي',
            'voucher_date' => now()->toDateString(),
        ]);
        $vouchers->post($vouchers->approve($voucher));

        $this->get(route('admin.accounting.cashboxes.show', $this->treasury))
            ->assertOk()
            ->assertSee('إيداع نقدي')
            ->assertSee('—');
    }

    /** والخدمة تُرجع الخريطتين معًا بمفتاح القيد. */
    public function test_the_service_returns_both_maps_keyed_by_entry(): void
    {
        $entryIds = app(TreasuryService::class)
            ->movements($this->treasury, 100)
            ->map(fn ($m) => $m->entry?->id)->filter()->unique()->all();

        $meta = app(TreasuryService::class)->entryMeta($entryIds);

        $this->assertContains('7441552', $meta['trackings']);
        $this->assertContains('رنا واتس', $meta['parties']);
    }

    /** ولا استعلامَ لكل صفّ — الكشف يعرض مئة حركة. */
    public function test_it_does_not_query_per_row(): void
    {
        $service = app(TreasuryService::class);
        $entryIds = $service->movements($this->treasury, 100)
            ->map(fn ($m) => $m->entry?->id)->filter()->unique()->all();

        DB::enableQueryLog();
        $service->entryMeta($entryIds);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // سندات + طلبات + علاقات السند المُحمَّلة مسبقًا — لا حلقةَ استعلامات.
        $this->assertLessThanOrEqual(6, $count);
    }
}
