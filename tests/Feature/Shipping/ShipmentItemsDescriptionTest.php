<?php

namespace Tests\Feature\Shipping;

use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * صيغة وصف الأصناف في حمولة الشحنة المرسلة لشركة التوصيل.
 *
 * الكمية بين نجمتين: «شواية متنقلة *2*» بدل «شواية متنقلة ×2» — بطلب صريح من
 * مالك النظام. الصيغة يقرأها موظفو شركة التوصيل على الملصق، فتغييرها قرارُ
 * تشغيل لا تفصيل تجميلي؛ ولذلك تُحرَس باختبار.
 */
class ShipmentItemsDescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** الوصف كما يُبنى في الحمولة (الدالة خاصّة، فتُقرأ عبر الانعكاس). */
    private function description(Order $order): string
    {
        $method = new \ReflectionMethod(OrderDeliveryDispatcher::class, 'buildPayload');
        $method->setAccessible(true);

        return $method->invoke(app(OrderDeliveryDispatcher::class), $order, null)['items_description'];
    }

    private function orderWith(array $lines): Order
    {
        $order = Order::create([
            'number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            'channel' => 'manual', 'status' => 'confirmed',
            'subtotal' => 100, 'total' => 100,
        ]);

        foreach ($lines as [$name, $qty]) {
            OrderItem::create([
                'order_id' => $order->id,
                'variant_id' => Product::factory()->create(['name' => $name])->defaultVariant->id,
                'qty' => $qty, 'unit_price' => 50, 'line_total' => 50 * $qty,
            ]);
        }

        return $order->fresh();
    }

    public function test_the_quantity_sits_between_two_stars(): void
    {
        $order = $this->orderWith([['شواية متنقلة', 2]]);

        $this->assertSame('شواية متنقلة *2*', $this->description($order));
    }

    public function test_the_old_multiplication_sign_is_gone(): void
    {
        $order = $this->orderWith([['شواية متنقلة', 2]]);

        $this->assertStringNotContainsString('×', $this->description($order));
    }

    public function test_several_lines_keep_the_separator(): void
    {
        $order = $this->orderWith([['شواية متنقلة', 2], ['فحم', 1]]);

        $this->assertSame('شواية متنقلة *2* , فحم *1*', $this->description($order));
    }

    public function test_a_whole_quantity_carries_no_decimals(): void
    {
        // الكمية `decimal` في قاعدة البيانات؛ «3.000» على الملصق تربك الموظف.
        $order = $this->orderWith([['منتج', 3]]);

        $this->assertSame('منتج *3*', $this->description($order));
    }
}
