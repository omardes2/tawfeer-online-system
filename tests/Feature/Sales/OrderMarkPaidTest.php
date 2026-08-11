<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderPaymentService;
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
 * وتجاوز يدوي لمدير النظام فقط حين لا تصل حالة المزوّد. في الحالتين يدخل التحصيل
 * «صندوق الأونلاين» بسند قبض مُرحّل: مدين الصندوق / دائن «ذمم شركة التوصيل 1050».
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

        // التأكيد يُرحّل قيد البيع (مدين «ذمم شركة التوصيل 1050» بالإجمالي) — كما في الواقع
        // قبل الإرسال لشركة التوصيل؛ سند التحصيل لاحقًا يقفل هذه الذمّة.
        app(OrderService::class)->confirm($order->fresh('items'));

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

    /**
     * التحصيل يدخل «صندوق الأونلاين» بسند قبض مُرحّل: مدين الصندوق بكامل الإجمالي /
     * دائن «ذمم شركة التوصيل 1050» — فيصفَّر رصيد الطلب على 1050.
     */
    public function test_funds_at_accounting_posts_collection_into_online_cashbox(): void
    {
        $s = $this->shipment();
        $order = $s->order;
        $total = (float) $order->total;

        // بعد تأكيد البيع (في المساعد): 1050 مدين بالإجمالي — التحصيل يجب أن يقفله.
        $codBefore = $this->balance('1050');
        $this->assertEqualsWithDelta($total, $codBefore, 0.001);

        $this->toAccounting($s);

        // سند قبض مُرحّل بمرجع رقم الطلب على خزينة صندوق الأونلاين.
        $treasury = OrderPaymentService::codTreasury();
        $this->assertNotNull($treasury);
        $this->assertEquals('CB-ONLINE', $treasury->code);

        $voucher = FinancialVoucher::where('reference', $order->number)->where('kind', 'receipt')->first();
        $this->assertNotNull($voucher);
        $this->assertEquals('posted', $voucher->status);
        $this->assertEqualsWithDelta($total, (float) $voucher->amount, 0.001);
        $this->assertEquals($treasury->id, $voucher->treasury_id);

        // الصندوق زاد بالإجمالي، وذمّة الطلب على 1050 أُقفلت (عاد الرصيد صفرًا).
        $this->assertEqualsWithDelta($total, $this->balance($treasury->glAccount->code), 0.001);
        $this->assertEqualsWithDelta($codBefore - $total, $this->balance('1050'), 0.001);
    }

    /** المدفوع إلكترونيًا مسبقًا: المندوب لم يقبض شيئًا ⇒ لا سند تحصيل عند in_accounting. */
    public function test_prepaid_order_gets_no_cod_collection_voucher(): void
    {
        $s = $this->shipment();
        $s->order->update(['payment_status' => 'paid', 'amount_paid' => $s->order->total]);

        $this->toAccounting($s);

        $this->assertNull(FinancialVoucher::where('reference', $s->order->number)->where('kind', 'receipt')->first());
    }

    private function balance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        return app(AccountingService::class)->accountBalance($account);
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

        // التجاوز اليدوي بنفس أثر in_accounting: سند تحصيل مُرحّل في صندوق الأونلاين.
        $voucher = FinancialVoucher::where('reference', $order->number)->where('kind', 'receipt')->first();
        $this->assertNotNull($voucher);
        $this->assertEquals('posted', $voucher->status);
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
