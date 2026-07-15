<?php

namespace Tests\Feature\Accounting;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerLedgerAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    private function arParent(): Account
    {
        return Account::where('code', '1100')->firstOrFail();
    }

    public function test_creating_customer_creates_sub_account_under_receivable(): void
    {
        $customer = app(CustomerService::class)->create([
            'branch_id' => Branch::default()->id, 'name' => 'عميل التجزئة', 'primary_phone' => '0599123456',
        ]);

        $this->assertNotNull($customer->gl_account_id);

        $account = $customer->glAccount;
        $this->assertSame($this->arParent()->id, $account->parent_id);
        $this->assertTrue($account->is_postable);
        $this->assertSame('asset', $account->type);
        $this->assertStringContainsString('عميل التجزئة', $account->name);
        $this->assertStringStartsWith('1100-', $account->code);
    }

    public function test_each_customer_gets_a_distinct_sub_account(): void
    {
        $c1 = app(CustomerService::class)->create(['branch_id' => Branch::default()->id, 'name' => 'عميل أ', 'primary_phone' => '0599000001']);
        $c2 = app(CustomerService::class)->create(['branch_id' => Branch::default()->id, 'name' => 'عميل ب', 'primary_phone' => '0599000002']);

        $this->assertNotSame($c1->gl_account_id, $c2->gl_account_id);
        $this->assertNotSame($c1->glAccount->code, $c2->glAccount->code);
    }

    public function test_direct_sale_posts_receivable_to_customer_sub_account(): void
    {
        $accounting = app(AccountingService::class);
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60);

        $customer = app(CustomerService::class)->create([
            'branch_id' => Branch::default()->id, 'name' => 'عميل الذمم', 'primary_phone' => '0599777000',
        ]);

        // بيع مباشر (channel=pos) لعميل مسجّل ⇒ ذمم العميل على حسابه الفرعي تحت 1100.
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'channel' => 'pos',
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => $customer->primary_phone,
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100]], 2026);

        app(OrderService::class)->confirm($order);

        $sub = $customer->fresh()->glAccount;
        $line = JournalLine::where('account_id', $sub->id)->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(200, (float) $line->debit, 0.01);
        $this->assertEqualsWithDelta(200, $accounting->accountBalance($sub), 0.01);
        $this->assertEqualsWithDelta(200, $accounting->accountBalance($this->arParent()), 0.01);
    }

    public function test_delivery_order_posts_to_cod_receivable_1050_without_customer_account(): void
    {
        $accounting = app(AccountingService::class);
        $warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $variant = Product::factory()->create()->defaultVariant;
        app(InventoryService::class)->receive($variant, $warehouse, 10, 60);

        $customer = app(CustomerService::class)->create([
            'branch_id' => Branch::default()->id, 'name' => 'مستلم التوصيل', 'primary_phone' => '0599111222',
        ]);

        // طلب عبر شركة التوصيل (channel=manual من «انشاء اوردر»).
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id, 'warehouse_id' => $warehouse->id, 'channel' => 'manual',
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'customer_phone' => $customer->primary_phone,
        ], [['variant_id' => $variant->id, 'qty' => 2, 'unit_price' => 100]], 2026);

        app(OrderService::class)->confirm($order);

        // الذمم على شركة التوصيل 1050، ولا شيء على حساب العميل الفرعي.
        $this->assertEqualsWithDelta(200, $accounting->accountBalance(Account::where('code', '1050')->firstOrFail()), 0.01);
        $sub = $customer->fresh()->glAccount;
        $this->assertSame(0, JournalLine::where('account_id', $sub->id)->count());
    }
}
