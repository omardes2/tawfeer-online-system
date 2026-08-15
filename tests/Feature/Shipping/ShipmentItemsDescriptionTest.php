<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    /** متغيّر بخيارات (لون/مقاس) مربوط ببند طلب. */
    private function orderWithVariant(string $product, array $options, float $qty): Order
    {
        $model = Product::factory()->create(['name' => $product]);

        $variant = ProductVariant::create([
            'product_id' => $model->id, 'sku' => $model->sku.'-V',
            'retail_price' => 50, 'is_active' => true,
        ]);

        foreach ($options as $attributeName => $value) {
            $attribute = ProductAttribute::create([
                'slug' => Str::slug($attributeName).'-'.fake()->unique()->numberBetween(1, 99999),
                'name' => $attributeName, 'is_active' => true,
            ]);
            $attributeValue = ProductAttributeValue::create([
                'attribute_id' => $attribute->id,
                'slug' => Str::slug($value).'-'.fake()->unique()->numberBetween(1, 99999),
                'value' => $value, 'label' => $value, 'is_active' => true,
            ]);
            $variant->attributeValues()->syncWithoutDetaching([$attributeValue->id]);
        }

        $order = Order::create([
            'number' => 'SO-'.fake()->unique()->numberBetween(100000, 999999),
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'customer_name' => 'زبون', 'customer_phone' => '0599000000',
            'channel' => 'manual', 'status' => 'confirmed',
            'subtotal' => 100, 'total' => 100,
        ]);

        OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => $qty, 'unit_price' => 50, 'line_total' => 50 * $qty,
        ]);

        return $order->fresh();
    }

    public function test_variant_options_travel_to_the_courier_between_stars(): void
    {
        // اللون والمقاس يميّزان الطرد عند التجهيز والتسليم — لا يكفي اسم المنتج.
        $order = $this->orderWithVariant('قميص قطني', ['اللون' => 'أحمر', 'المقاس' => 'L'], 2);

        $this->assertSame('قميص قطني *أحمر - L* *2*', $this->description($order));
    }

    public function test_an_item_without_options_gains_no_empty_stars(): void
    {
        $order = $this->orderWith([['شواية متنقلة', 1]]);

        $this->assertSame('شواية متنقلة *1*', $this->description($order));
        $this->assertStringNotContainsString('**', $this->description($order));
    }

    public function test_the_invoice_shows_the_options_too(): void
    {
        $order = $this->orderWithVariant('قميص قطني', ['اللون' => 'أزرق'], 1);
        $admin = User::where('email', 'admin@tawfeer.online')->first();

        $this->actingAs($admin)
            ->get(route('admin.sales.orders.invoice', $order))
            ->assertOk()
            ->assertSee('قميص قطني', false)
            ->assertSee('أزرق', false);
    }
}
