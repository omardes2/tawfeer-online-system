<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «تقديم الطلب» ذرّي: إمّا طلب مكتمل مُرحّل ومخصوم من المخزون، أو لا شيء إطلاقًا.
 * كان الفشل بعد الإنشاء يترك طلبًا معلّقًا لا يُرحَّل ولا يصل شركة التوصيل.
 */
class OrderSubmitIsAtomicTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function sales(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('sales');

        return $u;
    }

    /** مدينة/منطقة بسعر توصيل (التوصيل إلزامي على شاشة الطلب). */
    private function geo(): array
    {
        $gov = Governorate::firstOrCreate(['name' => 'فلسطين'], ['country_code' => 'PS', 'is_active' => true]);
        $city = City::firstOrCreate(['governorate_id' => $gov->id, 'name' => 'الخليل'], ['is_active' => true]);
        $area = Area::firstOrCreate(['city_id' => $city->id, 'name' => 'ابن رشد'], ['is_active' => true]);
        DeliveryCityRate::firstOrCreate(['city_id' => $city->id], ['name' => 'الخليل', 'delivery_fee' => 20, 'currency' => 'ILS', 'is_active' => true]);

        return ['city_id' => $city->id, 'area_id' => $area->id, 'shipping_address' => 'تست'];
    }

    private function variant(float $stock = 10)
    {
        $variant = Product::factory()->active()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $this->warehouse, $stock, 50);

        return $variant->fresh();
    }

    /** صنف بسعر صفر (نُسي تسعيره): يُرفض بالتحقّق ولا يُنشأ طلب. */
    public function test_zero_priced_item_is_rejected_and_no_order_is_created(): void
    {
        $variant = $this->variant();

        $this->actingAs($this->sales())->post('/admin/sales/orders', [
            'customer_name' => 'عمر شاهين', 'customer_phone' => '0599432037',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 1, 'unit_price' => 0]],
        ])->assertSessionHasErrors('items.0.unit_price');

        $this->assertSame(0, Order::count());
    }

    /** فشل أثناء المعالجة (كمية غير متوفرة) ⇒ لا طلب ولا حركة مخزون. */
    public function test_failure_during_fulfilment_rolls_back_the_whole_order(): void
    {
        $variant = $this->variant(stock: 1);
        $before = (float) InventoryStock::where('variant_id', $variant->id)->value('on_hand');

        $this->actingAs($this->sales())->post('/admin/sales/orders', [
            'customer_name' => 'عمر شاهين', 'customer_phone' => '0599432037',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 5, 'unit_price' => 100]], // أكثر من المتوفر
        ])->assertRedirect();

        $this->assertSame(0, Order::count());          // لا طلب معلّق
        $this->assertSame(0, Order::withTrashed()->count());
        $this->assertEqualsWithDelta($before, (float) InventoryStock::where('variant_id', $variant->id)->value('on_hand'), 0.001);
    }

    /** الطلب السليم يمرّ كاملًا: مُرحّل محاسبيًا ومخصوم من المخزون. */
    public function test_valid_order_is_created_and_posted(): void
    {
        $variant = $this->variant(stock: 10);

        $this->actingAs($this->sales())->post('/admin/sales/orders', [
            'customer_name' => 'عمر شاهين', 'customer_phone' => '0599432037',
            ...$this->geo(),
            'items' => [['variant' => $variant->uuid, 'qty' => 2, 'unit_price' => 100]],
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertNotNull($order->revenue_entry_id);
        $this->assertEqualsWithDelta(8, (float) InventoryStock::where('variant_id', $variant->id)->value('on_hand'), 0.001);
    }
}
