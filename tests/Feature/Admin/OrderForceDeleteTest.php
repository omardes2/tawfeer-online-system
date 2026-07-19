<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderForceDeleteTest extends TestCase
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

    public function test_admin_force_delete_reverses_accounting_and_stock(): void
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        $customer = Customer::factory()->create();

        // مخزون افتتاحي 10 قطع بتكلفة 5.
        app(InventoryService::class)->receive($variant, $warehouse, 10, 5);
        $onHand = fn () => (float) InventoryStock::where('variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand');
        $this->assertEqualsWithDelta(10, $onHand(), 0.001);

        // مبيعة مباشرة (تمرّ بكامل الدورة: ترحيل محاسبي + صرف مخزون).
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => '0599000000',
            'channel' => 'pos',
        ], [['variant_id' => $variant->id, 'qty' => 3, 'unit_price' => 100]], 2026);
        app(OrderService::class)->fulfillDirect($order);

        $this->assertEqualsWithDelta(7, $onHand(), 0.001); // خُصمت 3.
        $this->assertNotNull($order->fresh()->revenue_entry_id, 'يُفترض ترحيل قيد الإيراد');

        // تحصيل الدفعة إلى الصندوق.
        $cashbox = Treasury::where('type', 'cash')->whereNotNull('gl_account_id')->first();
        app(OrderPaymentService::class)->collect($order->fresh(), $cashbox->id, (float) $order->fresh()->total);
        $receiptId = FinancialVoucher::where('kind', 'receipt')->where('reference', $order->number)->value('id');
        $this->assertNotNull($receiptId);

        $revenueId = $order->fresh()->revenue_entry_id;
        $cogsId = $order->fresh()->cogs_entry_id;

        // الحذف الإداري النهائي.
        $this->actingAs($this->admin())
            ->delete(route('admin.sales.orders.force_destroy', $order))
            ->assertRedirect(route('admin.sales.orders.index'));

        // الطلب محذوف (ناعم)، والمخزون عاد، وقيدا الإيراد/التكلفة حُذفا (لا عُكسا)، وسند القبض عُكس.
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
        $this->assertEqualsWithDelta(10, $onHand(), 0.001);
        $this->assertNull($order->fresh()->revenue_entry_id);
        $this->assertNull($order->fresh()->cogs_entry_id);
        $this->assertNull(JournalEntry::find($revenueId), 'يُفترض حذف قيد الإيراد لا عكسه');
        $this->assertNull(JournalEntry::find($cogsId), 'يُفترض حذف قيد التكلفة لا عكسه');
        $this->assertSame('reversed', FinancialVoucher::find($receiptId)->status);
    }

    public function test_force_delete_forbidden_for_non_admin(): void
    {
        $manager = User::factory()->create(['branch_id' => Branch::default()->id]);
        $manager->assignRole('manager');
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_name' => 'ع', 'customer_phone' => '0599000000',
        ], [], 2026);

        $this->actingAs($manager)
            ->delete(route('admin.sales.orders.force_destroy', $order))
            ->assertForbidden();
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
    }
}
