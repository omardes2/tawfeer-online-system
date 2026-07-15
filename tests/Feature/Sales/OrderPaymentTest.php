<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderPaymentService;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private AccountingService $accounting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
        $this->accounting = app(AccountingService::class);
    }

    private function balance(string $code): float
    {
        return $this->accounting->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    /** طلب بيع مباشر لعميل: يُنشئ مديونية على حسابه، ثم يُحصَّل جزئيًا إلى خزينة. */
    private function directOrderForCustomer(): array
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $product = Product::factory()->create();
        $variant = $product->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60);

        $customer = Customer::factory()->create();
        app(CustomerService::class)->ensureLedgerAccount($customer);
        $customer->refresh();

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'customer_phone' => '0599000001', 'channel' => 'pos',
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100]], 2026);

        app(OrderService::class)->confirm($order);

        return [$order->fresh(), $customer, $customer->glAccount->code];
    }

    public function test_partial_payment_posts_receipt_to_treasury_and_reduces_receivable(): void
    {
        [$order, , $custCode] = $this->directOrderForCustomer();

        // مديونية العميل = 200 بعد التأكيد.
        $this->assertEqualsWithDelta(200, $this->balance($custCode), 0.01);

        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();
        $before = app(TreasuryService::class)->balance($treasury);

        $voucher = app(OrderPaymentService::class)->collect($order, $treasury->id, 120);

        $order->refresh();
        $this->assertSame('posted', $voucher->status);
        $this->assertEqualsWithDelta(120, (float) $order->amount_paid, 0.01);
        $this->assertSame('partially_paid', $order->payment_status);

        // مديونية العميل انخفضت إلى 80، والخزينة زادت 120.
        $this->assertEqualsWithDelta(80, $this->balance($custCode), 0.01);
        $this->assertEqualsWithDelta($before + 120, app(TreasuryService::class)->balance($treasury), 0.01);

        // السند مربوط بالعميل (يظهر اسمه في حركة الخزينة).
        $this->assertSame($order->customer_id, $voucher->customer_id);
    }

    public function test_full_payment_marks_order_paid(): void
    {
        [$order] = $this->directOrderForCustomer();
        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();

        app(OrderPaymentService::class)->collect($order, $treasury->id, 200);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(200, (float) $order->amount_paid, 0.01);
    }

    public function test_payment_above_outstanding_is_rejected(): void
    {
        [$order] = $this->directOrderForCustomer();
        $treasury = Treasury::where('code', 'CB-MAIN')->firstOrFail();

        $this->expectException(ValidationException::class);
        app(OrderPaymentService::class)->collect($order, $treasury->id, 250);
    }
}
