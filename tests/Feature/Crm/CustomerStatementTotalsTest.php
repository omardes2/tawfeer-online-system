<?php

namespace Tests\Feature\Crm;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use App\Modules\Sales\Services\OrderVoidService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إجماليات كشف حساب العميل بعد عكس القيود.
 *
 * البلاغ: أُنشئت فاتورة لعميل ثم حُذفت، فظهرت «مقبوضات ٧٠» لعميلٍ لم يدفع
 * شيئًا. السبب أن البطاقة كانت تجمع **كل** حركة دائنة على أنها قبض، وقيدُ
 * عكسِ الفاتورة المحذوفة دائنٌ على ذمّة العميل.
 *
 * الصواب: القيد العاكس يُطرح من الجانب الذي عكسه لا يُضاف إلى مقابله.
 */
class CustomerStatementTotalsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    private function customer(): Customer
    {
        return app(CustomerService::class)->create([
            'branch_id' => Branch::default()->id,
            'name' => 'عمر شاهين - عميل',
            'primary_phone' => '0599123456',
        ]);
    }

    /** بيع مباشر مؤكَّد على حساب العميل (يُرحَّل مدينًا على ذمّته). */
    private function postedSale(Customer $customer, float $amount): Order
    {
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, $amount / 2);

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'channel' => 'pos',
            'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'customer_phone' => $customer->primary_phone,
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => $amount]], 2026);

        app(OrderService::class)->confirm($order);

        return $order->fresh();
    }

    /** @return array{sales: float, received: float, balance: float} */
    private function tiles(Customer $customer): array
    {
        $view = $this->actingAs($this->admin)
            ->get(route('admin.crm.customers.show', $customer))
            ->assertOk();

        return [
            'sales' => (float) $view->viewData('sales'),
            'received' => (float) $view->viewData('received'),
            'balance' => (float) $view->viewData('balance'),
        ];
    }

    public function test_a_deleted_invoice_leaves_no_phantom_receipt(): void
    {
        $customer = $this->customer();
        $order = $this->postedSale($customer, 70);

        // قبل الحذف: مبيعة قائمة بلا قبض.
        $before = $this->tiles($customer);
        $this->assertEqualsWithDelta(70, $before['sales'], 0.01);
        $this->assertEqualsWithDelta(0, $before['received'], 0.01);

        app(OrderVoidService::class)->void($order, $this->admin);

        // بعد الحذف: لا مبيعة ولا قبض — العميل لم يدفع شيئًا وفاتورته محذوفة.
        $after = $this->tiles($customer);
        $this->assertEqualsWithDelta(0, $after['received'], 0.01, 'ظهرت مقبوضات لعميل لم يدفع.');
        $this->assertEqualsWithDelta(0, $after['sales'], 0.01, 'بقيت المبيعة قائمة بعد حذف فاتورتها.');
        $this->assertEqualsWithDelta(0, $after['balance'], 0.01);
    }

    public function test_the_statement_still_lists_both_movements(): void
    {
        // الكشف سجلٌّ محاسبي: الحركتان تبقيان ظاهرتين، والمعالجة في الإجماليات
        // لا بإخفاء حركة — إخفاؤها يكسر تطابق الكشف مع دفتر الأستاذ.
        $customer = $this->customer();
        $order = $this->postedSale($customer, 70);
        app(OrderVoidService::class)->void($order, $this->admin);

        $statement = $this->actingAs($this->admin)
            ->get(route('admin.crm.customers.show', $customer))
            ->assertOk()->viewData('statement');

        $this->assertCount(2, $statement);
        $this->assertEqualsWithDelta(70, $statement->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(70, $statement->sum('credit'), 0.01);
        // آخر رصيد متحرّك يطابق بطاقة الرصيد.
        $this->assertEqualsWithDelta(0, (float) $statement->last()['balance'], 0.01);
    }

    public function test_an_ordinary_sale_without_reversal_is_unaffected(): void
    {
        // شبكة أمان: العلاج لا يبتلع المبيعات العادية.
        $customer = $this->customer();
        $this->postedSale($customer, 120);

        $tiles = $this->tiles($customer);
        $this->assertEqualsWithDelta(120, $tiles['sales'], 0.01);
        $this->assertEqualsWithDelta(0, $tiles['received'], 0.01);
        $this->assertEqualsWithDelta(120, $tiles['balance'], 0.01);
    }
}
