<?php

namespace Tests\Feature\Reports;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Reporting\Services\ReportingService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رسمُ الصفحة الرئيسية يقرأ سلسلتين: **ما فُوتِر وما حُصِّل**.
 *
 * عمودُ المبيعات وحده يقول ما بِيع ولا يقول ما دخل الصندوق. والفجوة بينهما هي
 * الذمّة المفتوحة — وهي ما يُقرأ في شهرٍ مرتفع البيع ضعيف التحصيل، ولا يظهر
 * أثرُها في رقمٍ واحد مهما دُقّق.
 */
class MonthlySalesVsCollectedTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->warehouse = Warehouse::firstOrFail();
        $this->product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 200, 'wholesale_price' => 120,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);

        app(InventoryService::class)->openingStock($this->product->defaultVariant, $this->warehouse, 500, 100);
    }

    /** طلبٌ ببضاعةٍ ورسوم توصيل، ثم يُكتب عليه ما دُفع فعلًا. */
    private function order(float $goods, float $shipping, float $paid): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'رام الله', 'channel' => 'manual',
            'shipping_total' => $shipping,
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => 1, 'unit_price' => $goods,
        ]], (int) now()->year);

        $order->forceFill(['amount_paid' => $paid])->save();

        return $order->refresh();
    }

    /** @return array<string, mixed> شهرُ اليوم من السلسلة */
    private function thisMonth(): array
    {
        return app(ReportingService::class)->monthlySales((int) now()->year)
            ->firstWhere('month', (int) now()->month);
    }

    /** **السلسلتان تُرجَعان معًا: المفوتَر والمحصَّل.** */
    public function test_it_returns_both_invoiced_and_collected(): void
    {
        $this->order(goods: 620, shipping: 20, paid: 400);

        $row = $this->thisMonth();

        $this->assertEqualsWithDelta(620.0, $row['total'], 0.01);
        $this->assertEqualsWithDelta(400.0, $row['paid'], 0.01);
    }

    /**
     * **والمحصَّل يُسقَّف بقيمة البضاعة — لا يتجاوز الفواتير.**
     *
     * `amount_paid` يحمل ما دفعه الزبون شاملًا التوصيل. فطلبٌ مُسدَّد بالكامل
     * (٦٤٠) على مبيعاتٍ بلا توصيل (٦٢٠) كان سيرسم عمودَ تحصيلٍ أطول من عمود
     * البيع — ويُقرأ أنّ المحصَّل أكثر من المبيع.
     */
    public function test_the_collected_bar_never_exceeds_the_invoiced_one(): void
    {
        $this->order(goods: 620, shipping: 20, paid: 640);

        $row = $this->thisMonth();

        $this->assertEqualsWithDelta(620.0, $row['total'], 0.01);
        $this->assertEqualsWithDelta(620.0, $row['paid'], 0.01);
    }

    /** والفجوة تظهر حين يُحصَّل بعضُ الشهر: ذمّةٌ مفتوحة. */
    public function test_a_partially_collected_month_shows_the_gap(): void
    {
        $this->order(goods: 500, shipping: 20, paid: 500);
        $this->order(goods: 300, shipping: 20, paid: 0);

        $row = $this->thisMonth();

        $this->assertEqualsWithDelta(800.0, $row['total'], 0.01);
        $this->assertEqualsWithDelta(500.0, $row['paid'], 0.01);
    }

    /** والاثنا عشر شهرًا كلّها تُرجَع بالمفتاحين ولو كانت فارغة. */
    public function test_all_twelve_months_carry_both_keys(): void
    {
        $series = app(ReportingService::class)->monthlySales((int) now()->year);

        $this->assertCount(12, $series);
        foreach ($series as $row) {
            $this->assertArrayHasKey('total', $row);
            $this->assertArrayHasKey('paid', $row);
        }
    }

    /** والطلب الملغى لا يدخل أيًّا من السلسلتين. */
    public function test_a_cancelled_order_enters_neither_series(): void
    {
        $order = $this->order(goods: 900, shipping: 20, paid: 900);
        $order->forceFill(['status' => 'cancelled'])->save();

        $row = $this->thisMonth();

        $this->assertEqualsWithDelta(0.0, $row['total'], 0.01);
        $this->assertEqualsWithDelta(0.0, $row['paid'], 0.01);
    }
}
