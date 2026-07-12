<?php

namespace Tests\Feature\Payment;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentApiTest extends TestCase
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
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    private function order(float $qty = 4, float $price = 50): string
    {
        return $this->postJson('/api/v1/sales/orders', [
            'warehouse' => $this->warehouse->uuid,
            'customer_name' => 'عميل', 'customer_phone' => '0100000000',
            'items' => [['variant' => $this->variant->uuid, 'qty' => $qty, 'unit_price' => $price]],
        ])->assertCreated()->json('data.id'); // total 200
    }

    public function test_methods_list_returns_active_methods(): void
    {
        Sanctum::actingAs($this->admin());
        $res = $this->getJson('/api/v1/payments/methods')->assertOk();
        $codes = collect($res->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('cod'));
        $this->assertFalse($codes->contains('stripe')); // معطّل افتراضيًا
    }

    public function test_cod_initiate_is_pending_then_capture_marks_order_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();

        $res = $this->postJson('/api/v1/payments', [
            'order' => $orderId, 'method' => 'cod', 'amount' => 200,
        ])->assertCreated();
        $res->assertJsonPath('data.status', 'pending');
        $paymentId = $res->json('data.id');

        $this->postJson("/api/v1/payments/{$paymentId}/capture")->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertEquals('paid', Order::where('uuid', $orderId)->value('payment_status'));
        $this->assertEquals(200, (float) Order::where('uuid', $orderId)->value('amount_paid'));

        // مكشوف في مورد الطلب.
        $this->getJson("/api/v1/sales/orders/{$orderId}")->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.amount_paid', '200.00');
    }

    public function test_partial_payments_accumulate(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();

        $p1 = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 120])->json('data.id');
        $this->postJson("/api/v1/payments/{$p1}/capture")->assertOk();
        $this->assertEquals('partially_paid', Order::where('uuid', $orderId)->value('payment_status'));

        $p2 = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 80])->json('data.id');
        $this->postJson("/api/v1/payments/{$p2}/capture")->assertOk();
        $this->assertEquals('paid', Order::where('uuid', $orderId)->value('payment_status'));
    }

    public function test_refund_reduces_amount_paid(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();
        $pid = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 200])->json('data.id');
        $this->postJson("/api/v1/payments/{$pid}/capture");

        $this->postJson("/api/v1/payments/{$pid}/refund", ['amount' => 50])
            ->assertOk()->assertJsonPath('data.status', 'partially_refunded');
        $this->assertEquals('partially_paid', Order::where('uuid', $orderId)->value('payment_status'));
        $this->assertEquals(150, (float) Order::where('uuid', $orderId)->value('amount_paid'));

        // استرداد الباقي → مُسترَد بالكامل.
        $this->postJson("/api/v1/payments/{$pid}/refund")->assertOk()->assertJsonPath('data.status', 'refunded');
        $this->assertEquals('refunded', Order::where('uuid', $orderId)->value('payment_status'));
    }

    public function test_cannot_capture_a_paid_payment_again(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();
        $pid = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 200])->json('data.id');
        $this->postJson("/api/v1/payments/{$pid}/capture")->assertOk();

        $this->postJson("/api/v1/payments/{$pid}/capture")->assertUnprocessable();
    }

    public function test_refund_more_than_remaining_rejected(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();
        $pid = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 200])->json('data.id');
        $this->postJson("/api/v1/payments/{$pid}/capture");

        $this->postJson("/api/v1/payments/{$pid}/refund", ['amount' => 500])->assertUnprocessable();
    }

    public function test_online_gateway_stays_pending_with_null_driver(): void
    {
        Sanctum::actingAs($this->admin());
        // تفعيل hyperpay (driver=null) لاختبار مسار البوابة غير المُهيّأة.
        PaymentMethod::where('code', 'hyperpay')->update(['is_active' => true]);
        $orderId = $this->order();

        $res = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'hyperpay', 'amount' => 200])->assertCreated();
        $res->assertJsonPath('data.status', 'pending'); // Null driver لا يُحصّل
        $this->assertEquals('unpaid', Order::where('uuid', $orderId)->value('payment_status'));
    }

    public function test_cannot_pay_a_cancelled_order(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();
        $this->postJson("/api/v1/sales/orders/{$orderId}/cancel", ['reason' => 'ملغى']);

        $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 50])->assertUnprocessable();
    }

    public function test_amount_must_be_positive(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();

        $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 0])->assertUnprocessable();
    }

    public function test_sales_can_create_but_not_refund(): void
    {
        Sanctum::actingAs($this->admin());
        $orderId = $this->order();
        $pid = $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 200])->json('data.id');

        Sanctum::actingAs($this->withRole('sales')); // create+view فقط
        $this->postJson('/api/v1/payments', ['order' => $orderId, 'method' => 'cod', 'amount' => 10])->assertCreated();
        $this->postJson("/api/v1/payments/{$pid}/refund", ['amount' => 10])->assertForbidden();
    }
}
