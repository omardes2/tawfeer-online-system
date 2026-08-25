<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Reporting\Services\DeliveryCostReportService;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Sales\Models\Order;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * تقرير تكلفة التوصيل — ما دُفع للشركة وحده.
 *
 * غرضه المطابقة مع كشفها الشهري: مبلغٌ واحد تستلمه وتحتاج أن تعرف مِمَّ تكوّن.
 * فلا مبيعات فيه ولا ربح — خلطُهما يُفقده هذا الغرض.
 */
class DeliveryCostReportTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private City $nablus;

    private City $ramallah;

    private Area $rafidia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->warehouse = Warehouse::firstOrFail();

        $gov = Governorate::firstOrCreate(['name' => 'الشمال'], ['is_active' => true]);

        $this->nablus = City::create(['governorate_id' => $gov->id, 'name' => 'نابلس', 'is_active' => true]);
        $this->ramallah = City::create(['governorate_id' => $gov->id, 'name' => 'رام الله', 'is_active' => true]);
        $this->rafidia = Area::create(['city_id' => $this->nablus->id, 'name' => 'رفيديا', 'is_active' => true]);
    }

    private function ship(float $cost, ?City $city = null, ?Area $area = null, string $when = '2026-08-15'): Shipment
    {
        $order = Order::factory()->create(['branch_id' => Branch::default()->id]);

        $shipment = Shipment::create([
            'number' => 'SHP-'.uniqid(),
            'order_id' => $order->id,
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'delivered',
            'kind' => 'outbound',
            'recipient_name' => 'زبون',
            'recipient_phone' => '0599000000',
            'city_id' => $city?->id,
            'area_id' => $area?->id,
            'shipping_cost' => $cost,
        ]);

        // `created_at` خارج `fillable` للشحنة، فالإسناد الجماعي يُسقطه ويصير
        // التاريخ «الآن» — فيقع كلّ طردٍ في الشهر الجاري ولا يُختبر الفلتر شيئًا.
        $shipment->newQuery()->whereKey($shipment->id)->toBase()
            ->update(['created_at' => Carbon::parse($when)]);

        return $shipment->refresh();
    }

    private function rows(?int $cityId = null, ?int $areaId = null, string $from = '2026-08-01', string $to = '2026-08-31')
    {
        return app(DeliveryCostReportService::class)->rows(
            DateRange::resolve('custom', $from, $to), $cityId, $areaId,
        );
    }

    // ────────── الحساب ──────────

    /** المجموع هو ما دُفع للشركة، والعدد عدد الطرود. */
    public function test_it_sums_what_was_paid_per_city(): void
    {
        $this->ship(20, $this->nablus);
        $this->ship(25, $this->nablus);
        $this->ship(30, $this->ramallah);

        $rows = $this->rows();

        $nablus = $rows->firstWhere('city', 'نابلس');

        $this->assertSame(2, $nablus['parcels']);
        $this->assertEqualsWithDelta(45.0, $nablus['cost'], 0.01);
        $this->assertEqualsWithDelta(22.5, $nablus['avg'], 0.01);
    }

    /** والمنطقة تُفصَل عن مدينتها في صفٍّ مستقلّ. */
    public function test_areas_are_separate_rows(): void
    {
        $this->ship(20, $this->nablus, $this->rafidia);
        $this->ship(30, $this->nablus);

        $rows = $this->rows();

        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(20.0, $rows->firstWhere('area', 'رفيديا')['cost'], 0.01);
    }

    /**
     * **ولا مبيعات في التقرير إطلاقًا.**
     *
     * هذا شرط الغرض: الرقم يُطابَق بفاتورة الشركة، وأيّ مبلغ بيعٍ فيه يُفسده.
     */
    public function test_it_reports_no_sales_figures(): void
    {
        $this->ship(20, $this->nablus);

        $row = $this->rows()->first();

        foreach (['sales', 'revenue', 'profit', 'net_profit'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row);
        }

        $this->assertSame(
            ['city_id', 'area_id', 'city', 'area', 'parcels', 'cost', 'unpriced', 'avg'],
            array_keys($row),
        );
    }

    /** وطردٌ بلا رسمٍ مكتوب يُعدّ ولا يُخفى — يفسّر فرق المطابقة. */
    public function test_unpriced_parcels_are_counted(): void
    {
        $this->ship(20, $this->nablus);
        $this->ship(0, $this->nablus);

        $row = $this->rows()->first();

        $this->assertSame(2, $row['parcels']);
        $this->assertSame(1, $row['unpriced']);
        $this->assertEqualsWithDelta(20.0, $row['cost'], 0.01);
    }

    /** وطردٌ بلا مدينة يظهر بعنوانٍ صريح لا يسقط. */
    public function test_a_shipment_without_a_city_still_appears(): void
    {
        $this->ship(15);

        $this->assertEqualsWithDelta(15.0, $this->rows()->firstWhere('city', 'بلا مدينة')['cost'], 0.01);
    }

    // ────────── المرشّحات ──────────

    /** فلتر الفترة على تاريخ إنشاء الطرد — الشركة تُحاسب على ما أُرسل. */
    public function test_the_period_filter_uses_the_dispatch_month(): void
    {
        $this->ship(20, $this->nablus, when: '2026-08-15');
        $this->ship(99, $this->nablus, when: '2026-07-15');

        $this->assertEqualsWithDelta(20.0, (float) $this->rows()->sum('cost'), 0.01);
    }

    /** وفلتر المدينة يحصر النتيجة بها. */
    public function test_the_city_filter_narrows_the_report(): void
    {
        $this->ship(20, $this->nablus);
        $this->ship(30, $this->ramallah);

        $rows = $this->rows(cityId: $this->ramallah->id);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(30.0, $rows->first()['cost'], 0.01);
    }

    /** وفلتر المنطقة يحصرها أكثر. */
    public function test_the_area_filter_narrows_further(): void
    {
        $this->ship(20, $this->nablus, $this->rafidia);
        $this->ship(30, $this->nablus);

        $rows = $this->rows(cityId: $this->nablus->id, areaId: $this->rafidia->id);

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(20.0, $rows->first()['cost'], 0.01);
    }

    // ────────── الشاشة ──────────

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->firstOrFail();
    }

    /** الشاشة تفتح لمدير النظام. */
    public function test_the_screen_opens(): void
    {
        $this->ship(20, $this->nablus);

        $this->actingAs($this->admin())
            ->get(route('admin.reports.delivery_cost', ['from' => '2026-08-01', 'to' => '2026-08-31', 'range' => 'custom']))
            ->assertOk()
            ->assertSee('نابلس');
    }

    /** وتُغلق على من لا يملك صلاحيتها — رقم تكلفةٍ تفاوضيّ لا يُفتح للجميع. */
    public function test_the_screen_is_closed_to_other_roles(): void
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole('sales');

        $this->actingAs($user)->get(route('admin.reports.delivery_cost'))->assertForbidden();
    }

    /** والتصدير يُنزّل ملفًّا بالمجموع. */
    public function test_the_export_downloads_a_csv(): void
    {
        $this->ship(20, $this->nablus);

        $response = $this->actingAs($this->admin())->get(route('admin.reports.delivery_cost', [
            'from' => '2026-08-01', 'to' => '2026-08-31', 'range' => 'custom', 'export' => 'csv',
        ]))->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('نابلس', $response->streamedContent());
    }
}
