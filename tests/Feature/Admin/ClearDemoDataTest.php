<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FinancialVoucher;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\Treasury;
use App\Modules\Catalog\Models\Product;
use App\Modules\Crm\Models\Customer;
use App\Modules\Crm\Services\CustomerService;
use App\Modules\Foundation\Models\PaymentMethod;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Sales\Models\Order;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ClearDemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_clear_wipes_content_but_keeps_base_structure(): void
    {
        // قبل: يوجد محتوى تجريبي.
        $this->assertGreaterThan(0, Product::count());
        $this->assertGreaterThan(0, Order::count());
        $this->assertGreaterThan(0, Customer::count());
        $this->assertGreaterThan(0, Supplier::count());

        // العميل التجريبي أُنشئ بـ Customer::create مباشرة (بلا حساب فرعي)؛ ننشئ له حسابًا
        // فرعيًا عبر الخدمة لنتحقّق أن الأمر يحذف حسابات العملاء الفرعية (1100-%).
        app(CustomerService::class)->ensureLedgerAccount(Customer::first());
        $this->assertGreaterThan(0, Account::where('code', 'like', '1100-%')->count());

        Artisan::call('demo:clear', ['--force' => true]);

        // بعد: المحتوى فُرّغ.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Supplier::count());
        $this->assertSame(0, FinancialVoucher::count());
        $this->assertSame(0, JournalEntry::count());

        // البنية الأساسية محفوظة.
        $this->assertNotNull(User::where('email', 'admin@tawfeer.online')->first());
        $this->assertNull(User::where('email', 'sales@tawfeer.online')->first()); // موظف ديمو حُذف
        $this->assertGreaterThan(0, Warehouse::count());
        $this->assertGreaterThan(0, PaymentMethod::count());
        $this->assertNotNull(Treasury::where('code', 'CB-MAIN')->first());
        $this->assertNotNull(Account::where('code', '1100')->first()); // رأس ذمم العملاء يبقى
        $this->assertNotNull(Account::where('code', '6000')->first()); // تكلفة البضاعة يبقى
        $this->assertSame(0, Account::where('code', 'like', '1100-%')->count()); // الفرعية حُذفت
    }

    public function test_dry_run_deletes_nothing(): void
    {
        $before = Product::count();
        Artisan::call('demo:clear', ['--dry-run' => true]);
        $this->assertSame($before, Product::count());
    }
}
