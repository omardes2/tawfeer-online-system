<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * كشف حساب المسوّق يُقرأ طرحًا: **سعر المنتج − سعر الجملة = الربح**.
 *
 * وكان يعرض «الأساس» و«القيمة» — وهما للمسوّق **رقمٌ واحد**: قاعدة `margin`
 * تُعطيه الهامش كاملًا، فيتساوى العمودان ويُكتب الربح مرّتين بينما السعر الذي
 * بِيع به فعلًا لا يظهر أصلًا. وبه وحده يُراجَع السطر أمام الفاتورة.
 */
class StatementColumnsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->affiliate = User::factory()->create(['name' => 'مسوّق الكشف']);
        $this->affiliate->assignRole('affiliate');

        $this->product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 227, 'wholesale_price' => 100,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);

        app(InventoryService::class)->openingStock($this->product->defaultVariant, $this->warehouse, 100, 80);
    }

    /** طلبُ مسوّقٍ ببندٍ واحد — كميّته قابلة للتغيير لاختبار الضرب. */
    private function order(float $unitPrice = 227, float $qty = 1): Order
    {
        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'رام الله', 'channel' => 'manual',
            'affiliate_id' => $this->affiliate->id,
            'shipping_total' => 20,
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => $qty, 'unit_price' => $unitPrice,
        ]], (int) now()->year);
    }

    private function entryFor(Order $order): CommissionEntry
    {
        app(CommissionService::class)->accrueForOrder($order->fresh('items.variant'));

        return CommissionEntry::where('order_id', $order->id)
            ->where('earner_type', 'affiliate')->firstOrFail();
    }

    // ────────── الأعمدة الثلاثة ──────────

    /** **سعر المنتج يُقرأ من بند الطلب لا من `basis`** — الأخير هو الهامش. */
    public function test_the_sale_price_is_the_item_price_not_the_basis(): void
    {
        $entry = $this->entryFor($this->order(unitPrice: 227));

        $this->assertEqualsWithDelta(227.0, $entry->saleValue(), 0.01);
        $this->assertEqualsWithDelta(100.0, $entry->costValue(), 0.01);
        // والهامش (وهو `basis`) لا يساوي سعر البيع.
        $this->assertEqualsWithDelta(127.0, (float) $entry->basis, 0.01);
    }

    /** والطرح يصحّ: سعر المنتج − سعر الجملة = الربح. */
    public function test_the_three_columns_read_as_a_subtraction(): void
    {
        $entry = $this->entryFor($this->order(unitPrice: 227));

        $this->assertEqualsWithDelta(
            $entry->saleValue() - $entry->costValue(),
            (float) $entry->amount,
            0.01,
        );
    }

    /**
     * **والعمودان قيمةُ السطر لا سعر الوحدة.**
     *
     * الربح يُحتسب على الكمية، فلو عُرض السعران للوحدة لما صحّ الطرح على بندٍ
     * كميّتُه أكثر من واحد — ويقرأ المراجع فرقًا لا يطابق العمود الثالث.
     */
    public function test_the_prices_are_line_totals_not_unit_prices(): void
    {
        $entry = $this->entryFor($this->order(unitPrice: 227, qty: 3));

        $this->assertEqualsWithDelta(681.0, $entry->saleValue(), 0.01);   // ٢٢٧ × ٣
        $this->assertEqualsWithDelta(300.0, $entry->costValue(), 0.01);   // ١٠٠ × ٣
        $this->assertEqualsWithDelta(381.0, (float) $entry->amount, 0.01);
        $this->assertEqualsWithDelta(
            $entry->saleValue() - $entry->costValue(), (float) $entry->amount, 0.01,
        );
    }

    /** وحركة البائع بلا سعر شراء — نسبةٌ من المبيعات لا هامش. */
    public function test_a_sales_entry_has_no_buy_price(): void
    {
        $entry = new CommissionEntry(['earner_type' => 'sales', 'basis' => 500, 'amount' => 25]);

        $this->assertEqualsWithDelta(500.0, $entry->saleValue(), 0.01);
        $this->assertNull($entry->costValue());
    }

    // ────────── فلتر الحالة ──────────

    /** الكشف يعرض «مستحقّة» وحدها افتراضًا — لا «قيد الانتظار». */
    public function test_the_statement_shows_only_eligible_by_default(): void
    {
        $pending = $this->entryFor($this->order());
        $eligible = $this->entryFor($this->order());
        $eligible->update(['state' => 'eligible']);

        $response = $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate',
        ]));

        $response->assertOk()
            ->assertSee($eligible->order->number)
            ->assertDontSee($pending->order->number);
    }

    /** ويظلّ «قيد الانتظار» في متناول من يطلبه من الفلتر. */
    public function test_the_pending_state_stays_reachable_through_the_filter(): void
    {
        $pending = $this->entryFor($this->order());

        $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate', 'state' => 'pending',
        ]))->assertOk()->assertSee($pending->order->number);
    }

    /** وحالةٌ مجهولة من العنوان لا تُفلتِر بها — تعود إلى الافتراض. */
    public function test_an_unknown_state_falls_back_to_eligible(): void
    {
        $eligible = $this->entryFor($this->order());
        $eligible->update(['state' => 'eligible']);

        $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate', 'state' => 'nonsense',
        ]))->assertOk()->assertSee($eligible->order->number);
    }

    /**
     * **والرصيد لا يتبع الفلتر.**
     *
     * بطاقة «مستحق الفترة» تقول ما للمستفيد كاملًا؛ وربطُها بالعرض يجعل الرقم
     * يتغيّر بتغيّر ما يُنظَر إليه وهو لا يتغيّر.
     */
    public function test_the_period_total_ignores_the_state_filter(): void
    {
        $eligible = $this->entryFor($this->order());
        $eligible->update(['state' => 'eligible']);

        $amount = number_format((float) $eligible->amount, 2);

        $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate', 'state' => 'pending',
        ]))->assertOk()->assertSee($amount);
    }

    // ────────── التصدير ──────────

    /** التصدير ملفُّ xlsx حقيقيّ لا CSV بامتدادٍ مُضلِّل. */
    public function test_the_export_is_a_real_xlsx_file(): void
    {
        $entry = $this->entryFor($this->order());
        $entry->update(['state' => 'eligible']);

        $response = $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate', 'export' => 'xlsx',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
        // الاسم الذي يصل المتصفّح في الترويسة لا اسم الملفّ المؤقّت على القرص.
        $this->assertStringContainsString('.xlsx', $response->headers->get('content-disposition'));
        // ملفّ xlsx أرشيفُ zip — يبدأ بتوقيعه.
        $this->assertSame('PK', substr(file_get_contents($response->getFile()->getPathname()), 0, 2));
    }

    /** والرابط القديم `export=csv` يُنتج xlsx أيضًا — لا يكسر مفضّلةً محفوظة. */
    public function test_the_legacy_csv_link_now_yields_xlsx(): void
    {
        $this->entryFor($this->order());

        $response = $this->actingAs($this->admin)->get(route('admin.commissions.statement', [
            'earnerId' => $this->affiliate->id, 'earner_type' => 'affiliate', 'export' => 'csv',
        ]));

        $response->assertOk();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));
    }
}
