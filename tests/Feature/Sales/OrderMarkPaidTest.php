<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Shipping\Models\Shipment;
use App\Modules\Shipping\Services\DeliveryStatusService;
use App\Support\Contracts\Shipping\DeliveryProviderInterface;
use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تحوّل فاتورة طلب التوصيل إلى «مدفوعة» عند وصول المبلغ لمحاسبة المندوب (Opost: in_accounting)،
 * وتجاوز يدوي لمدير النظام فقط حين لا تصل حالة المزوّد. لا قيد محاسبي جديد في الحالتين:
 * المبلغ مُثبَت على «ذمم شركة التوصيل 1050» منذ البيع ويُقفَل بتسوية التوصيل.
 */
class OrderMarkPaidTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private DeliveryStatusService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        app()->bind(DeliveryProviderInterface::class, fn () => new OpostDeliveryProvider);
        $this->svc = app(DeliveryStatusService::class);
    }

    private function actor(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function order(float $price = 100, float $qty = 2, float $shipping = 20): Order
    {
        $product = Product::factory()->active()->create(['visibility' => 'visible', 'retail_price' => $price]);
        $variant = $product->defaultVariant;
        $variant->update(['retail_price' => $price]);
        app(InventoryService::class)->receive($variant, $this->warehouse, 50, 60);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $this->warehouse->id,
            'customer_id' => null, 'customer_name' => 'x', 'customer_phone' => '0500000000',
        ], [['variant_id' => $variant->fresh()->id, 'qty' => $qty, 'unit_price' => $price, 'discount' => 0]], 2026);

        // رسوم توصيل تُضاف للإجمالي (المندوب يحصّلها من العميل ضمن مبلغ COD).
        $order->update([
            'shipping_total' => $shipping,
            'total' => round((float) $order->total + $shipping, 2),
        ]);

        return $order->fresh('items');
    }

    private function shipment(?Order $order = null): Shipment
    {
        $order ??= $this->order();

        return Shipment::create([
            'number' => 'SHP-P-'.$order->id,
            'order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'warehouse_id' => $order->warehouse_id,
            'status' => 'not_shipped',
            'recipient_name' => 'x', 'recipient_phone' => '0500000000',
            'delivery_status' => 'draft',
        ]);
    }

    private function toAccounting(Shipment $s): void
    {
        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markDeliveredCodHeld($s);
        $this->svc->markFundsAtAccounting($s); // Opost: in_accounting
    }

    // ---- التحوّل التلقائي عند in_accounting ----

    public function test_funds_at_accounting_marks_invoice_paid(): void
    {
        $s = $this->shipment();
        $total = (float) $s->order->total; // 200 بضاعة + 20 توصيل

        // قبل الوصول للمحاسبة: غير مدفوعة.
        $this->assertNotEquals('paid', $s->order->payment_status);

        $this->toAccounting($s);

        $order = $s->order->fresh();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEqualsWithDelta($total, (float) $order->amount_paid, 0.001);
    }

    /** لا قيد محاسبي جديد: الذمّة على شركة التوصيل مثبتة منذ البيع وتُقفَل بالتسوية. */
    public function test_marking_paid_posts_no_extra_journal_entry(): void
    {
        $s = $this->shipment();
        $before = JournalEntry::count();

        $this->toAccounting($s);

        $this->assertEquals($before, JournalEntry::count());
    }

    /** الحالة السابقة على in_accounting (النقد لدى المندوب) لا تُسدِّد الفاتورة. */
    public function test_delivered_cod_held_does_not_mark_paid(): void
    {
        $s = $this->shipment();

        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markDeliveredCodHeld($s);

        $this->assertNotEquals('paid', $s->order->fresh()->payment_status);
    }

    /** مسار الإرجاع لا يمرّ بـin_accounting ⇒ تبقى غير مدفوعة. */
    public function test_return_path_does_not_mark_paid(): void
    {
        $s = $this->shipment();

        $this->svc->submit($s);
        $this->svc->pickup($s);
        $this->svc->markReturningToCourier($s);
        $this->svc->markReturnInTransit($s);
        $this->svc->close($s, $this->actor('finance'));

        $this->assertNotEquals('paid', $s->order->fresh()->payment_status);
    }

    // ---- التجاوز اليدوي: مدير النظام فقط ----

    public function test_admin_can_mark_delivery_invoice_paid(): void
    {
        $s = $this->shipment();
        $this->svc->submit($s); // أُرسل لشركة التوصيل

        $this->actingAs($this->actor('admin'))
            ->post(route('admin.sales.orders.mark_paid', $s->order))
            ->assertRedirect()->assertSessionHas('success');

        $order = $s->order->fresh();
        $this->assertEquals('paid', $order->payment_status);
        $this->assertEqualsWithDelta((float) $order->total, (float) $order->amount_paid, 0.001);
    }

    public function test_non_admin_cannot_mark_invoice_paid(): void
    {
        $s = $this->shipment();
        $this->svc->submit($s);

        $this->actingAs($this->actor('sales'))
            ->post(route('admin.sales.orders.mark_paid', $s->order))
            ->assertForbidden();

        $this->assertNotEquals('paid', $s->order->fresh()->payment_status);
    }

    /** قبل الإرسال لشركة التوصيل: يُمنع (وإلا خرجت الشحنة بمبلغ تحصيل صفر). */
    public function test_order_without_shipment_cannot_be_marked_paid(): void
    {
        $order = $this->order();

        $this->actingAs($this->actor('admin'))
            ->post(route('admin.sales.orders.mark_paid', $order))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNotEquals('paid', $order->fresh()->payment_status);
    }

    /** المبيعات المباشرة تُسدَّد بسند قبض (خزينة)، لا بهذا التجاوز. */
    public function test_direct_sale_is_rejected_by_manual_mark_paid(): void
    {
        $order = $this->order();
        $order->update(['channel' => 'pos']);

        $this->actingAs($this->actor('admin'))
            ->post(route('admin.sales.orders.mark_paid', $order))
            ->assertRedirect()->assertSessionHas('error');

        $this->assertNotEquals('paid', $order->fresh()->payment_status);
    }

    /** idempotent: طلب مسدَّد لا يُسدَّد مرّتين. */
    public function test_already_paid_order_is_rejected(): void
    {
        $s = $this->shipment();
        $this->toAccounting($s);

        $this->actingAs($this->actor('admin'))
            ->post(route('admin.sales.orders.mark_paid', $s->order->fresh()))
            ->assertRedirect()->assertSessionHas('error');
    }
}
