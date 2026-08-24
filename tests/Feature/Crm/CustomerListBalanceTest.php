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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * عمود «الرصيد المتبقي» في قائمة العملاء.
 *
 * الفحص الحاسم ليس «هل ظهر العمود؟» بل **«هل يطابق رقمُ القائمة رقمَ
 * البطاقة؟»** — رقمان مختلفان لنفس العميل في شاشتين يُسقطان الثقة في
 * الاثنين معًا، ولا يُعرف أيّهما يُصدَّق.
 */
class CustomerListBalanceTest extends TestCase
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

    private function customer(string $name = 'عميل'): Customer
    {
        return app(CustomerService::class)->create([
            'branch_id' => Branch::default()->id,
            'name' => $name,
            'primary_phone' => '05991'.random_int(10000, 99999),
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

    private function listedBalance(Customer $customer): float
    {
        $customers = $this->actingAs($this->admin)
            ->get(route('admin.crm.customers.index'))
            ->assertOk()
            ->viewData('customers');

        return (float) $customers->firstWhere('id', $customer->id)->outstandingBalance();
    }

    private function cardBalance(Customer $customer): float
    {
        return (float) $this->actingAs($this->admin)
            ->get(route('admin.crm.customers.show', $customer))
            ->assertOk()->viewData('balance');
    }

    /** الشاشة تعرض العمود. */
    public function test_the_list_shows_the_balance_column(): void
    {
        $this->customer();

        $this->actingAs($this->admin)
            ->get(route('admin.crm.customers.index'))
            ->assertOk()
            ->assertSee('الرصيد المتبقي');
    }

    /** والبيع على الحساب يظهر رصيدًا على العميل. */
    public function test_a_posted_sale_shows_as_an_outstanding_balance(): void
    {
        $customer = $this->customer();
        $this->postedSale($customer, 120);

        $this->assertEqualsWithDelta(120, $this->listedBalance($customer), 0.01);
    }

    /**
     * **ورقم القائمة يطابق رقم البطاقة.**
     *
     * هذا هو الاختبار الذي يعني شيئًا: حسابان مستقلّان في شاشتين، وأيّ فرقٍ
     * بينهما يعني أن أحدهما خاطئ ولا يُعرف أيّهما.
     */
    public function test_the_listed_balance_matches_the_customer_card(): void
    {
        $customer = $this->customer();
        $this->postedSale($customer, 250);

        $this->assertEqualsWithDelta(
            $this->cardBalance($customer),
            $this->listedBalance($customer),
            0.01,
            'رقم القائمة يخالف رقم بطاقة العميل.',
        );
    }

    /** ويطابقه بعد عكس الفاتورة أيضًا — لا يبقى دَينٌ لفاتورةٍ محذوفة. */
    public function test_it_matches_the_card_after_a_void(): void
    {
        $customer = $this->customer();
        $order = $this->postedSale($customer, 70);

        app(OrderVoidService::class)->void($order, $this->admin);

        $this->assertEqualsWithDelta(0, $this->listedBalance($customer), 0.01);
        $this->assertEqualsWithDelta($this->cardBalance($customer), $this->listedBalance($customer), 0.01);
    }

    /** وعميلٌ بلا حركةٍ رصيدُه صفر لا فراغ. */
    public function test_a_customer_with_no_movement_reads_zero(): void
    {
        $this->assertSame(0.0, $this->listedBalance($this->customer()));
    }

    /**
     * والقيود غير المُرحّلة لا تُحتسب.
     *
     * مسوّدةٌ لم تدخل الدفاتر بعد؛ وإدخالُها في رصيدٍ يُطالَب به العميل مطالبةٌ
     * بما لم يُعتمد.
     */
    public function test_draft_entries_are_excluded(): void
    {
        $customer = $this->customer();
        $this->postedSale($customer, 100);

        // تحويل قيود العميل كلّها إلى مسوّدة: الرصيد يجب أن يعود صفرًا.
        DB::table('journal_entries')
            ->whereIn('id', DB::table('journal_lines')
                ->where('account_id', $customer->fresh()->gl_account_id)
                ->pluck('journal_entry_id'))
            ->update(['status' => 'draft']);

        $this->assertSame(0.0, $this->listedBalance($customer));
    }

    /**
     * وكلّ عميلٍ يحمل رصيده هو.
     *
     * استعلامٌ فرعيّ خاطئ الربط يُعطي الرصيد نفسه للجميع، وهو خطأٌ يمرّ بصمت
     * حين يُختبَر عميلٌ واحد.
     */
    public function test_each_customer_carries_its_own_balance(): void
    {
        $a = $this->customer('عميل أ');
        $b = $this->customer('عميل ب');

        $this->postedSale($a, 300);
        $this->postedSale($b, 45);

        $this->assertEqualsWithDelta(300, $this->listedBalance($a), 0.01);
        $this->assertEqualsWithDelta(45, $this->listedBalance($b), 0.01);
    }

    /**
     * والقائمة لا تستعلم مرّةً لكل عميل.
     *
     * قراءة الرصيد من العلاقة داخل حلقة العرض تُنتج استعلامًا لكل صفّ — يمرّ
     * على ثلاثة عملاء ويُسقط الصفحة على ثلاثة آلاف.
     */
    public function test_the_list_does_not_query_per_customer(): void
    {
        foreach (range(1, 5) as $i) {
            $this->postedSale($this->customer("عميل {$i}"), 10 * $i);
        }

        DB::enableQueryLog();

        $this->actingAs($this->admin)->get(route('admin.crm.customers.index'))->assertOk();

        $balanceQueries = collect(DB::getRawQueryLog())
            ->filter(fn ($q) => str_contains($q['raw_query'], 'journal_lines'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $balanceQueries, 'الرصيد يُقرأ باستعلامٍ لكل عميل.');
    }
}
