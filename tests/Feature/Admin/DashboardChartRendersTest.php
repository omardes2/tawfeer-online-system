<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * رسمُ «الفواتير مقابل المحصَّل» يُرسم فعلًا — لا أرقامًا فوق فراغ.
 *
 * الأعمدة تُرسم بنسبةٍ مئوية، والنسبة لا تُحلّ إلا على أبٍ ارتفاعُه معلوم. وكان
 * الأب عمودًا في صفٍّ `items-end` فلا يمتدّ إلى ارتفاع الصفّ بل يقصر على محتواه،
 * فتُحلّ `height: 62%` على `auto` فتصير صفرًا: تظهر الأرقام فوق الأعمدة وأسماء
 * الشهور تحتها ولا عمود بينهما.
 *
 * وهو عطبٌ لا يكشفه اختبارُ خدمةٍ مهما دقّ: الأرقام كانت صحيحة والرسم فارغ.
 */
class DashboardChartRendersTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();

        $warehouse = Warehouse::firstOrFail();
        $product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 200, 'wholesale_price' => 120,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
        app(InventoryService::class)->openingStock($product->defaultVariant, $warehouse, 100, 100);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $warehouse->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599111222',
            'shipping_address' => 'رام الله', 'channel' => 'manual',
            'shipping_total' => 20,
        ], [[
            'variant_id' => $product->defaultVariant->id, 'qty' => 1, 'unit_price' => 500,
        ]], (int) now()->year);

        // مُحصَّل جزئيًّا: عمودان بطولين مختلفين، وهو ما رُسم الشكل لإظهاره.
        $order->forceFill(['amount_paid' => 300])->save();
    }

    private function dashboard(): string
    {
        return $this->actingAs($this->admin)->get(route('admin.dashboard'))
            ->assertOk()->getContent();
    }

    /** **منطقة الأعمدة ذات ارتفاعٍ صريح** — بغيره لا تُحلّ النسب فيبقى الرسم فارغًا. */
    public function test_the_plot_area_has_an_explicit_height(): void
    {
        $this->assertStringContainsString(
            'h-48 w-full flex items-end justify-center',
            $this->dashboard(),
            'منطقة الأعمدة بلا ارتفاع صريح — النِّسَب لن تُحلّ والرسم سيبقى فارغًا.',
        );
    }

    /** ويُرسم عمودان بطولين مختلفين: المفوتَر أطول من المحصَّل. */
    public function test_it_draws_two_bars_of_different_heights(): void
    {
        $html = $this->dashboard();

        // ٥٠٠ مفوتَر و٣٠٠ محصَّل على مقياسٍ أقصاه ٥٠٠ ⇒ ١٠٠٪ و٦٠٪.
        $this->assertStringContainsString('style="height: 100%"', $html);
        $this->assertStringContainsString('style="height: 60%"', $html);
    }

    /** واللونان مميَّزان، ولكلٍّ مفتاحُه في الأعلى. */
    public function test_both_series_are_labelled(): void
    {
        $html = $this->dashboard();

        $this->assertStringContainsString('bg-emerald-500', $html);
        $this->assertStringContainsString('bg-sky-500', $html);
        $this->assertStringContainsString('الفواتير', $html);
        $this->assertStringContainsString('المحصَّل', $html);
    }

    /** والاثنا عشر شهرًا كلّها مرسومة ولو كان أكثرها فارغًا. */
    public function test_all_twelve_months_are_drawn(): void
    {
        $this->assertSame(12, substr_count($this->dashboard(), 'h-48 w-full flex items-end justify-center'));
    }
}
