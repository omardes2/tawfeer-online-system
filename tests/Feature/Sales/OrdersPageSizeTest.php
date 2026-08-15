<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * حجم صفحة طلبات البيع: خمسون لا عشرون.
 *
 * رقمٌ في سطر واحد، لكنه يحدّد كم طلبًا يرى المشغّل قبل أن يضطرّ للتنقّل —
 * فيُحرَس كي لا يعود إلى ٢٠ في أول تعديل على الاستعلام.
 */
class OrdersPageSizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function makeOrders(int $count): void
    {
        $branch = Branch::default()->id;
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id;

        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'number' => 'SO-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'branch_id' => $branch, 'warehouse_id' => $warehouse,
                'customer_name' => 'زبون '.$i, 'customer_phone' => '0599000000',
                'channel' => 'manual', 'status' => 'confirmed',
                'subtotal' => 10, 'total' => 10,
            ]);
        }
    }

    public function test_the_page_shows_fifty_orders(): void
    {
        $this->makeOrders(55);

        $orders = $this->actingAs(User::where('email', 'admin@tawfeer.online')->first())
            ->get(route('admin.sales.orders.index'))
            ->assertOk()
            ->viewData('orders');

        $this->assertSame(50, $orders->perPage());
        $this->assertCount(50, $orders->items());
        // والباقي على صفحة ثانية — لا يُقتطع صامتًا.
        $this->assertSame(55, $orders->total());
    }
}
