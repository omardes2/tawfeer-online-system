<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierLedgerAccountTest extends TestCase
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

    private function apParent(): Account
    {
        return Account::where('code', config('accounting.purchasing.payable_account'))->firstOrFail();
    }

    public function test_creating_supplier_creates_sub_account_under_payable(): void
    {
        $supplier = app(SupplierService::class)->create([
            'branch_id' => Branch::default()->id, 'name' => 'شركة الأمل', 'code' => 'SUP-AMAL',
        ]);

        $this->assertNotNull($supplier->gl_account_id);

        $account = $supplier->glAccount;
        $this->assertSame($this->apParent()->id, $account->parent_id);
        $this->assertTrue($account->is_postable);
        $this->assertSame('liability', $account->type);
        $this->assertStringContainsString('شركة الأمل', $account->name);
        $this->assertStringStartsWith($this->apParent()->code.'-', $account->code);
    }

    public function test_each_supplier_gets_a_distinct_sub_account(): void
    {
        $s1 = app(SupplierService::class)->create(['branch_id' => Branch::default()->id, 'name' => 'مورد أ', 'code' => 'SUP-A']);
        $s2 = app(SupplierService::class)->create(['branch_id' => Branch::default()->id, 'name' => 'مورد ب', 'code' => 'SUP-B']);

        $this->assertNotSame($s1->gl_account_id, $s2->gl_account_id);
        $this->assertNotSame($s1->glAccount->code, $s2->glAccount->code);
    }

    public function test_invoice_posts_to_supplier_sub_account_not_general_payable(): void
    {
        $accounting = app(AccountingService::class);
        $service = app(PurchaseInvoiceService::class);

        $supplier = app(SupplierService::class)->create(['branch_id' => Branch::default()->id, 'name' => 'مورد المخزن', 'code' => 'SUP-WH']);
        $variant = ProductVariant::factory()->create();

        $invoice = $service->create(
            ['supplier_id' => $supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $variant->id, 'qty' => 10, 'unit_cost' => 50, 'tax_rate' => 0]],
        );
        $service->approve($invoice);
        $service->post($invoice->fresh());

        $subAccount = $supplier->fresh()->glAccount;

        // القيد الدائن على حساب المورد الفرعي.
        $line = JournalLine::where('account_id', $subAccount->id)->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(500, (float) $line->credit, 0.01);

        // رصيد الفرعي = 500، ورصيد الأب (مراقبة) يشمله = 500.
        $this->assertEqualsWithDelta(500, $accounting->accountBalance($subAccount), 0.01);
        $this->assertEqualsWithDelta(500, $accounting->accountBalance($this->apParent()), 0.01);
    }
}
