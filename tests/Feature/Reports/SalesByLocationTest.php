<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المبيعات حسب المدن والمناطق.
 *
 * تقريرٌ للقراءة يجمع مبيعات الطلبات تحت مدنها ومناطقها. القيمتان اللتان
 * يحرسهما الاختبار: أن يُجمَّع الصحيح تحت الصحيح، وأن يطابق المجموعُ مبيعات
 * الفترة — فالطلب يقع في منطقة واحدة، وطلبٌ بلا منطقة أو مدينة لا يجوز أن يسقط
 * من المجموع.
 */
class SalesByLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function city(string $name): City
    {
        $gov = Governorate::query()->firstOrCreate(['name' => 'محافظة تجريبية'], ['is_active' => true]);

        return City::create(['governorate_id' => $gov->id, 'name' => $name, 'is_active' => true]);
    }

    private function area(City $city, string $name): Area
    {
        return Area::create(['city_id' => $city->id, 'name' => $name, 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $location */
    private function order(float $unitPrice, array $location): Order
    {
        $variant = Product::factory()->create()->defaultVariant;
        $variant->update(['average_cost' => 0]);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
        ] + $location, [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => $unitPrice]], 2026);

        $order->update($location + ['status' => 'delivered']);

        return $order->refresh();
    }

    private function report(): array
    {
        $res = $this->actingAs($this->admin)->get(route('admin.reports.sales.by_location'))->assertOk();

        return [
            'cities' => $res->viewData('cities'),
            'orders' => $res->viewData('totalOrders'),
            'sales' => round((float) $res->viewData('totalSales'), 2),
        ];
    }

    // ────────── التجميع ──────────

    /** المبيعات تُجمَّع تحت مدينتها ومنطقتها الصحيحتين. */
    public function test_sales_group_under_the_right_city_and_area(): void
    {
        $nablus = $this->city('نابلس');
        $rafidia = $this->area($nablus, 'رفيديا');
        $downtown = $this->area($nablus, 'وسط البلد');

        $this->order(100, ['city_id' => $nablus->id, 'area_id' => $rafidia->id]);
        $this->order(50, ['city_id' => $nablus->id, 'area_id' => $rafidia->id]);
        $this->order(70, ['city_id' => $nablus->id, 'area_id' => $downtown->id]);

        $data = $this->report();
        $city = $data['cities']->firstWhere('city', 'نابلس');

        $this->assertNotNull($city);
        $this->assertSame(3, $city['orders_count']);
        $this->assertEqualsWithDelta(220.0, $city['sales_total'], 0.01);

        // المناطق مرتَّبة بالمبيعات تنازليًّا: رفيديا (150) قبل وسط البلد (70).
        $this->assertSame('رفيديا', $city['areas'][0]['area']);
        $this->assertSame(2, $city['areas'][0]['orders_count']);
        $this->assertEqualsWithDelta(150.0, $city['areas'][0]['sales_total'], 0.01);
    }

    /** والمدن مرتَّبة بالمبيعات تنازليًّا. */
    public function test_cities_are_ordered_by_sales_descending(): void
    {
        $a = $this->city('رام الله');
        $b = $this->city('الخليل');
        $this->order(300, ['city_id' => $b->id, 'area_id' => $this->area($b, 'حي')->id]);
        $this->order(100, ['city_id' => $a->id, 'area_id' => $this->area($a, 'حي')->id]);

        $cities = $this->report()['cities'];

        $this->assertSame('الخليل', $cities[0]['city']);
        $this->assertSame('رام الله', $cities[1]['city']);
    }

    // ────────── المطابقة ──────────

    /**
     * الطلب بلا منطقة أو بلا مدينة يظهر مجمَّعًا لا يسقط.
     *
     * وإلا صار مجموعُ الصفحة أقلّ من مبيعات الفترة بلا سببٍ ظاهر للقارئ.
     */
    public function test_orders_without_a_city_or_area_are_kept(): void
    {
        $city = $this->city('جنين');
        $this->order(80, ['city_id' => $city->id, 'area_id' => null]);      // بلا منطقة
        $this->order(40, ['city_id' => null, 'area_id' => null]);           // بلا مدينة

        $data = $this->report();

        $this->assertNotNull($data['cities']->firstWhere('city', 'بلا مدينة محدّدة'));
        $jenin = $data['cities']->firstWhere('city', 'جنين');
        $this->assertSame('بلا منطقة محدّدة', $jenin['areas'][0]['area']);

        // المجموع يشمل الاثنين.
        $this->assertEqualsWithDelta(120.0, $data['sales'], 0.01);
    }

    /** ومجموع الطلبات عبر المناطق يطابق طلبات الفترة — بلا ازدواج. */
    public function test_the_order_total_reconciles_with_the_period(): void
    {
        $city = $this->city('طولكرم');
        $area = $this->area($city, 'حي');
        $this->order(100, ['city_id' => $city->id, 'area_id' => $area->id]);
        $this->order(100, ['city_id' => $city->id, 'area_id' => $area->id]);

        $data = $this->report();
        $sumOfRows = $data['cities']->sum('orders_count');

        $this->assertSame($data['orders'], $sumOfRows);
        $this->assertSame(2, $data['orders']);
    }

    // ────────── الصلاحية ──────────

    /** بلا صلاحية تقارير المبيعات لا يُفتح التقرير. */
    public function test_it_requires_the_reports_permission(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('warehouse');

        $this->actingAs($user)->get(route('admin.reports.sales.by_location'))->assertForbidden();
    }
}
