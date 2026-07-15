<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function order(string $status): Order
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'ع', 'customer_phone' => '0599000000',
        ], [], 2026);
        $order->update(['status' => $status]);

        return $order;
    }

    /** طلب مستوفٍ لشرط الحذف: ملغى + شحنته ملغاة لدى المزوّد. */
    private function deletableOrder(): Order
    {
        $order = $this->order('cancelled');
        $provider = DeliveryProvider::firstOrCreate(['code' => 'opost'], ['name' => 'Opost', 'driver' => 'opost']);
        Shipment::create([
            'number' => 'SHP-'.$order->id, 'order_id' => $order->id, 'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id, 'status' => 'not_shipped',
            'recipient_name' => 'ع', 'recipient_phone' => '0599000000',
            'delivery_provider_id' => $provider->id, 'provider_status' => 'cancelled',
        ]);

        return $order;
    }

    public function test_bulk_delete_removes_only_deletable_orders(): void
    {
        $deletable = $this->deletableOrder();
        $notDeletable = $this->order('draft');

        $this->actingAs($this->admin())
            ->delete(route('admin.sales.orders.bulk_destroy'), ['ids' => [$deletable->id, $notDeletable->id]])
            ->assertRedirect(route('admin.sales.orders.index'));

        $this->assertSoftDeleted('orders', ['id' => $deletable->id]);
        $this->assertNotSoftDeleted('orders', ['id' => $notDeletable->id]);
    }

    public function test_bulk_delete_with_no_ids_reports_error(): void
    {
        $this->actingAs($this->admin())
            ->delete(route('admin.sales.orders.bulk_destroy'), ['ids' => []])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_bulk_delete_forbidden_without_permission(): void
    {
        $accountant = User::factory()->create(['branch_id' => Branch::default()->id]);
        $accountant->assignRole('accountant');

        $order = $this->deletableOrder();

        $this->actingAs($accountant)
            ->delete(route('admin.sales.orders.bulk_destroy'), ['ids' => [$order->id]])
            ->assertForbidden();

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }
}
