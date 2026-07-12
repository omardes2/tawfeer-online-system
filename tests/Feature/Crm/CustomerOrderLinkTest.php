<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->first();
        $this->variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($this->variant, $this->warehouse, 100, 10);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function makeCustomer(array $overrides = []): Customer
    {
        return app(CustomerService::class)->create(array_merge([
            'name' => 'رامي', 'primary_phone' => '0500000000',
        ], $overrides));
    }

    public function test_order_links_to_customer_and_snapshots_from_it(): void
    {
        Sanctum::actingAs($this->admin());
        $customer = $this->makeCustomer(['email' => 'r@x.com']);

        // إنشاء طلب مربوط بالعميل دون تمرير الاسم/الهاتف — يُشتقّ لقطةً.
        $res = $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer' => $customer->uuid,
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertCreated();

        $orderId = $res->json('data.id');
        $order = Order::where('uuid', $orderId)->first();
        $this->assertEquals($customer->id, $order->customer_id);
        $this->assertEquals('رامي', $order->customer_name); // لقطة من العميل
    }

    public function test_customer_edit_does_not_change_historical_order_snapshot(): void
    {
        Sanctum::actingAs($this->admin());
        $customer = $this->makeCustomer();

        $orderId = $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer' => $customer->uuid,
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->json('data.id');

        // تعديل اسم العميل لاحقًا.
        app(CustomerService::class)->update($customer, ['name' => 'رامي المُعدّل']);

        // لقطة الطلب التاريخية لا تتغيّر (المتطلّب: snapshots ثابتة).
        $this->assertEquals('رامي', Order::where('uuid', $orderId)->value('customer_name'));
        // الربط يبقى، وسجلّ طلبات العميل يعرض الطلب.
        $this->assertEquals(1, $customer->fresh()->orders()->count());
    }

    public function test_blocked_customer_cannot_place_order(): void
    {
        Sanctum::actingAs($this->admin());
        $customer = $this->makeCustomer();
        app(CustomerService::class)->block($customer, 'احتيال');

        $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer' => $customer->uuid,
            'customer_name' => 'رامي', 'customer_phone' => '05',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertUnprocessable();
    }

    public function test_order_without_customer_still_works(): void
    {
        Sanctum::actingAs($this->admin());

        // توافق رجعي: طلب بلا عميل CRM (لقطة يدوية) — كما في 2.6.
        $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'زائر', 'customer_phone' => '0590000000',
            'items' => [['variant' => $this->variant->uuid, 'qty' => 1, 'unit_price' => 50]],
        ])->assertCreated()->assertJsonPath('data.customer.name', 'زائر');
    }
}
