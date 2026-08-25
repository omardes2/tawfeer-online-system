<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * مرشّحات صفحة الطلبات وتصديرها.
 *
 * وفلتر المستخدم **لمن يرى الجميع وحده**: القائمة تكشف من يبيع كم، وهو ما لا
 * يُفتح لموظفي المبيعات بعضهم على بعض. والحصر بالصلاحية لا بالاسم (المبدأ 11).
 */
class OrderListFiltersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $this->affiliate = User::factory()->create([
            'name' => 'سائد شاهين', 'branch_id' => Branch::default()->id,
        ]);
        $this->affiliate->assignRole('affiliate');
    }

    private function order(array $attributes = []): Order
    {
        // مستودعٌ واحد لكل الطلبات: مصنع الطلب يُنشئ `Warehouse::factory()` لكل
        // صفٍّ، فينفد مجال أكواده الفريدة عند عشرات الطلبات.
        $order = Order::factory()->create($attributes + [
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
        ]);

        if (isset($attributes['created_at'])) {
            $order->newQuery()->whereKey($order->id)->toBase()
                ->update(['created_at' => $attributes['created_at']]);
        }

        return $order->refresh();
    }

    private function index(array $query = [])
    {
        return $this->actingAs($this->admin)->get(route('admin.sales.orders.index', $query));
    }

    // ────────── فلتر التاريخ ──────────

    /** «من تاريخ» تحصر ما قبله. */
    public function test_the_from_date_excludes_earlier_orders(): void
    {
        $old = $this->order(['created_at' => Carbon::parse('2026-07-10')]);
        $new = $this->order(['created_at' => Carbon::parse('2026-08-20')]);

        $this->index(['from' => '2026-08-01'])
            ->assertOk()->assertSee($new->number)->assertDontSee($old->number);
    }

    /** و«إلى تاريخ» تحصر ما بعده — شاملةً يومَها كاملًا. */
    public function test_the_to_date_includes_its_whole_day(): void
    {
        $inside = $this->order(['created_at' => Carbon::parse('2026-08-31 23:30:00')]);
        $outside = $this->order(['created_at' => Carbon::parse('2026-09-01 00:10:00')]);

        $this->index(['to' => '2026-08-31'])
            ->assertOk()->assertSee($inside->number)->assertDontSee($outside->number);
    }

    /** والطرفان مستقلّان: «من» وحدها تصحّ. */
    public function test_either_end_works_alone(): void
    {
        $this->order(['created_at' => Carbon::parse('2026-08-20')]);

        $this->index(['from' => '2026-08-01'])->assertOk();
        $this->index(['to' => '2026-08-31'])->assertOk();
    }

    // ────────── فلتر المستخدم ──────────

    /** مدير النظام يرى المرشّح. */
    public function test_the_admin_sees_the_user_filter(): void
    {
        $this->index()->assertOk()->assertSee('كل المستخدمين');
    }

    /** ويُصفّي به على المسوّق. */
    public function test_it_filters_by_the_affiliate(): void
    {
        $his = $this->order(['affiliate_id' => $this->affiliate->id]);
        $other = $this->order();

        $this->index(['user_id' => $this->affiliate->id])
            ->assertOk()->assertSee($his->number)->assertDontSee($other->number);
    }

    /** ويشمل الطلبات المُسنَدة إليه لا المسوَّقة وحدها — كعمود «المستخدم» تمامًا. */
    public function test_it_also_matches_assigned_orders(): void
    {
        $seller = User::factory()->create(['branch_id' => Branch::default()->id]);
        $seller->assignRole('sales');

        $assigned = $this->order(['assigned_to' => $seller->id]);

        $this->index(['user_id' => $seller->id])->assertOk()->assertSee($assigned->number);
    }

    /**
     * **ومن لا يرى الجميع لا يرى المرشّح ولا يعمل معه.**
     *
     * إخفاؤه من الشاشة وحدها لا يكفي: من يعرف المعامل يكتبه في العنوان.
     */
    public function test_a_seller_gets_neither_the_filter_nor_its_effect(): void
    {
        $seller = User::factory()->create(['branch_id' => Branch::default()->id]);
        $seller->assignRole('sales');

        $response = $this->actingAs($seller)
            ->get(route('admin.sales.orders.index', ['user_id' => $this->affiliate->id]));

        $response->assertOk()->assertDontSee('كل المستخدمين');
    }

    // ────────── التصدير ──────────

    /** التصدير يُنزّل ملفًّا. */
    public function test_the_export_downloads_a_csv(): void
    {
        $this->order();

        $response = $this->index(['export' => 'csv'])->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    /** ويحترم الفلاتر المطبَّقة. */
    public function test_the_export_respects_the_filters(): void
    {
        $his = $this->order(['affiliate_id' => $this->affiliate->id]);
        $other = $this->order();

        $csv = $this->index(['user_id' => $this->affiliate->id, 'export' => 'csv'])->streamedContent();

        $this->assertStringContainsString($his->number, $csv);
        $this->assertStringNotContainsString($other->number, $csv);
        $this->assertStringContainsString('سائد شاهين', $csv);
    }

    /** ويُصدّر كل النتائج لا الصفحة الأولى (خمسون). */
    public function test_the_export_is_not_limited_to_one_page(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $this->order(['number' => 'SO-TEST-'.$i]);
        }

        $csv = $this->index(['export' => 'csv'])->streamedContent();

        // 55 سطرًا + ترويسة + سطران للمجموع.
        $this->assertSame(55, substr_count($csv, 'SO-'));
    }

    // ────────── تمييز المسوّق ──────────

    /** اسم المسوّق وصفته بالأخضر. */
    public function test_the_affiliate_is_highlighted_in_green(): void
    {
        $this->order(['affiliate_id' => $this->affiliate->id]);

        $html = $this->index()->assertOk()->getContent();

        $this->assertStringContainsString('text-emerald-700 font-medium">سائد شاهين', $html);
        $this->assertStringContainsString('text-emerald-600 font-medium">المسوّق', $html);
    }

    /** وموظف المبيعات يبقى رماديًّا — التمييز يفقد معناه إن عمّ. */
    public function test_a_sales_employee_stays_gray(): void
    {
        $seller = User::factory()->create(['name' => 'هالة الايوبي', 'branch_id' => Branch::default()->id]);
        $seller->assignRole('sales');

        $this->order(['assigned_to' => $seller->id]);

        $html = $this->index()->assertOk()->getContent();

        $this->assertStringContainsString('text-gray-700">هالة الايوبي', $html);
    }
}
