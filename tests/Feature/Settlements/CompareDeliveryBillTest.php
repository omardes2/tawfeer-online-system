<?php

namespace Tests\Feature\Settlements;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * مطابقة فاتورة شركة التوصيل بطلبات النظام.
 *
 * الفاتورة تصل بمئات الشحنات، وأخطر ما فيها ليس المجموع بل **السطر الشاذّ**:
 * شحنةٌ في الفاتورة لا طلبَ لها عندنا، أو طلبٌ استلمنا مالَه ولم يُعلَّم مدفوعًا.
 * والمجموع وحده يُخفي هذين لأنهما قد يتعادلان.
 *
 * أساس المقارنة **COD − Fees**: هو ما يدخل دفاترنا (`total − shipping_total`).
 * والرسوم الإضافية تُطرح من الصافي المُستلم ولا يقابلها بندٌ في قيمة البضاعة.
 */
class CompareDeliveryBillTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Product $product;

    private string $csv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->warehouse = Warehouse::firstOrFail();
        $this->product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 500, 'wholesale_price' => 300,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($this->product->defaultVariant, $this->warehouse, 500, 200);

        $this->csv = tempnam(sys_get_temp_dir(), 'bill').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->csv);
        parent::tearDown();
    }

    private function order(string $tracking, float $goods, float $shipping, string $paymentStatus = 'paid'): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'الخليل', 'channel' => 'manual',
            'shipping_total' => $shipping,
        ], [[
            'variant_id' => $this->product->defaultVariant->id, 'qty' => 1, 'unit_price' => $goods,
        ]], (int) now()->year);

        $order->forceFill(['tracking_number' => $tracking, 'payment_status' => $paymentStatus])->save();

        return $order->refresh();
    }

    /** @param  array<int, array<int, mixed>>  $rows */
    private function bill(array $rows): void
    {
        $out = fopen($this->csv, 'w');
        fputcsv($out, ['tracking', 'cod', 'fees', 'extra']);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
    }

    private function compare(): string
    {
        Artisan::call('delivery:compare-bill', ['file' => $this->csv, '--limit' => 50]);

        return Artisan::output();
    }

    // ────────── المطابقة السليمة ──────────

    /** **COD ناقص Fees يطابق قيمة البضاعة عندنا.** */
    public function test_a_matching_bill_reports_no_difference(): void
    {
        $this->order('7441552', goods: 1557, shipping: 63);
        $this->bill([['OmmaR-48-007441552', 1620, 63, 330]]);

        $output = $this->compare();

        $this->assertStringContainsString('لا شيء', $output);
        $this->assertStringContainsString('1,557.00', $output);
    }

    /**
     * **والرسوم الإضافية خارج المقارنة.**
     *
     * الـ330 في السطر أعلاه لا يقابلها بندٌ في قيمة البضاعة، فطرحُها من الطرفين
     * كان سيُظهر فرقًا وهميًّا. وتبقى مطروحةً من **الصافي المُستلم** وحده.
     */
    public function test_extra_fees_are_excluded_from_the_comparison_but_not_the_net(): void
    {
        $this->order('7441552', goods: 1557, shipping: 63);
        $this->bill([['OmmaR-48-007441552', 1620, 63, 330]]);

        $output = $this->compare();

        $this->assertStringContainsString('1,557.00', $output);  // قيمة البضاعة
        $this->assertStringContainsString('1,227.00', $output);  // الصافي: 1620 − 63 − 330
    }

    // ────────── السطور الشاذّة ──────────

    /** **شحنةٌ في الفاتورة بلا طلبٍ عندنا** — مالٌ استُلم عن بيعةٍ لا نعرفها. */
    public function test_it_flags_a_shipment_with_no_order(): void
    {
        $this->bill([['OmmaR-48-007999999', 400, 63, 0]]);

        $output = $this->compare();

        $this->assertStringContainsString('سطورٌ في الفاتورة بلا طلبٍ عندنا', $output);
        $this->assertStringContainsString('7999999', $output);
    }

    /** وفرقٌ في قيمة البضاعة يُعرض بطرفيه. */
    public function test_it_flags_a_value_difference(): void
    {
        $this->order('7441552', goods: 1500, shipping: 63);
        $this->bill([['OmmaR-48-007441552', 1620, 63, 0]]);

        $output = $this->compare();

        $this->assertStringContainsString('فروقٌ في قيمة البضاعة', $output);
        $this->assertStringContainsString('57.00', $output); // 1557 − 1500
    }

    /**
     * **وطلبٌ استلمنا مالَه ولم يُعلَّم مدفوعًا.**
     *
     * خللٌ في الحالة لا في المبلغ، ولا يظهر في أيّ مجموع — وهو أكثر ما يُفسد
     * تقارير التحصيل لاحقًا.
     */
    public function test_it_flags_an_order_that_is_not_marked_paid(): void
    {
        $this->order('7441552', goods: 1557, shipping: 63, paymentStatus: 'unpaid');
        $this->bill([['OmmaR-48-007441552', 1620, 63, 0]]);

        $output = $this->compare();

        $this->assertStringContainsString('لم تُعلَّم مدفوعة', $output);
    }

    // ────────── صيغ أرقام التتبّع ──────────

    /** أرقام التتبّع تُطابَق مهما اختلفت صيغتها. */
    public function test_it_matches_across_tracking_formats(): void
    {
        $this->order('7441552', goods: 1557, shipping: 63);

        foreach (['OmmaR-48-007441552', '007441552', '7441552'] as $written) {
            $this->bill([[$written, 1620, 63, 0]]);

            $this->assertStringContainsString('1 / 1', $this->compare(), "تعذّرت المطابقة على الصيغة: {$written}");
        }
    }

    // ────────── الإجماليات ──────────

    /** والإجماليات تُجمع على كل السطور. */
    public function test_it_totals_the_whole_bill(): void
    {
        $this->order('7441552', goods: 1557, shipping: 63);
        $this->order('7447796', goods: 337, shipping: 63);
        $this->bill([
            ['OmmaR-48-007441552', 1620, 63, 330],
            ['OmmaR-48-007447796', 400, 63, 0],
        ]);

        $output = $this->compare();

        $this->assertStringContainsString('2,020.00', $output); // COD
        $this->assertStringContainsString('1,894.00', $output); // COD − Fees
        $this->assertStringContainsString('1,564.00', $output); // الصافي المُستلم
    }

    /** وملفٌ غير موجود يُرفض بوضوح لا بانهيار. */
    public function test_a_missing_file_fails_cleanly(): void
    {
        $code = Artisan::call('delivery:compare-bill', ['file' => '/tmp/لا-يوجد.csv']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('تعذّر قراءة الملف', Artisan::output());
    }
}
