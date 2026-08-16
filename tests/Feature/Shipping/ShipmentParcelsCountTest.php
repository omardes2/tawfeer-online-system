<?php

namespace Tests\Feature\Shipping;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use App\Modules\Shipping\Services\OrderDeliveryDispatcher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * الكمية المُرسَلة لشركة التوصيل = **عدد الطرود** لا عدد القطع.
 *
 * مجموع القطع كان يقول للشركة «عندي 20 طردًا» لطلبٍ يُسلَّم في كيسٍ واحد، فتَرفضه
 * (سقفها 12) ويدور في حلقة محاولات لا تنجح — رفضُ 422 دائمٌ لا يُصلحه التكرار.
 *
 * وهو حقلُ شحنٍ بحت: القطع تبقى مفصّلة في وصف الشحنة، والفاتورة والمخزون
 * والعمولات تقرأ كميات البنود ولا تتأثّر.
 */
class ShipmentParcelsCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    /** طلبٌ بـ20 قطعة موزّعة على بندين. */
    private function orderWithPieces(int $parcels = 1): Order
    {
        $order = Order::factory()->create(['parcels_count' => $parcels, 'subtotal' => 2000, 'total' => 2000]);

        foreach ([12, 8] as $qty) {
            OrderItem::create([
                'order_id' => $order->id,
                'variant_id' => ProductVariant::factory()->create()->id,
                'qty' => $qty, 'unit_price' => 100, 'line_total' => $qty * 100,
            ]);
        }

        return $order->fresh('items');
    }

    /** @return array<string, mixed> */
    private function payload(Order $order): array
    {
        $method = new \ReflectionMethod(OrderDeliveryDispatcher::class, 'buildPayload');
        $method->setAccessible(true);

        return $method->invoke(app(OrderDeliveryDispatcher::class), $order, null);
    }

    public function test_the_default_order_ships_as_one_parcel(): void
    {
        $order = $this->orderWithPieces();

        // 20 قطعة في طردٍ واحد — لا 20.
        $this->assertSame(1, $this->payload($order)['quantity']);
    }

    public function test_multiple_boxes_are_sent_as_written(): void
    {
        $order = $this->orderWithPieces(parcels: 3);

        $this->assertSame(3, $this->payload($order)['quantity']);
    }

    public function test_a_missing_or_zero_value_falls_back_to_one(): void
    {
        // طلبات أُنشئت قبل وجود الحقل: طردٌ واحد أسلمُ من صفرٍ يرفضه المزوّد.
        $order = $this->orderWithPieces();
        $order->forceFill(['parcels_count' => 0])->saveQuietly();

        $this->assertSame(1, $this->payload($order->fresh('items'))['quantity']);
    }

    public function test_the_pieces_stay_in_the_shipment_description(): void
    {
        // العدد تغيّر، لا التفصيل: من يجهّز الطرد ما زال يرى القطع وكمياتها.
        $payload = $this->payload($this->orderWithPieces());

        $this->assertStringContainsString('*12*', $payload['items_description']);
        $this->assertStringContainsString('*8*', $payload['items_description']);
    }

    public function test_the_cod_amount_is_untouched(): void
    {
        // حقل شحنٍ بحت: المبلغ المُحصَّل يبقى إجمالي الطلب.
        $order = $this->orderWithPieces(parcels: 4);

        $this->assertEqualsWithDelta((float) $order->total, (float) $this->payload($order)['cod_amount'], 0.01);
    }

    public function test_the_order_form_offers_the_field(): void
    {
        $this->get(route('admin.sales.orders.create'))
            ->assertOk()
            ->assertSee('name="parcels_count"', false)
            ->assertSee(__('عدد الطرود'), false);
    }

    public function test_more_parcels_than_the_provider_accepts_is_refused(): void
    {
        // السقف يُفحص قبل الإرسال: رفض 422 لا تنفع معه إعادة المحاولة.
        $cap = (int) config('shipping.max_parcels_per_shipment', 12);
        $order = Order::factory()->create();

        $this->from(route('admin.sales.orders.edit', $order))
            ->put(route('admin.sales.orders.update', $order), [
                'customer_name' => 'محمود حمودي',
                'customer_phone' => '0599123456',
                'shipping_address' => 'نابلس — شارع تونس',
                'parcels_count' => $cap + 1,
                'items' => [[
                    'variant' => ProductVariant::factory()->create()->uuid,
                    'qty' => 1, 'unit_price' => 100,
                ]],
            ])
            ->assertSessionHasErrors('parcels_count');
    }

    public function test_the_cap_comes_from_configuration(): void
    {
        // سقفٌ يُغيَّر من الإعدادات لا رقمٌ محفور في الكود — يُرفع متى رفعه المزوّد.
        $this->assertSame(12, (int) config('shipping.max_parcels_per_shipment'));
    }
}
