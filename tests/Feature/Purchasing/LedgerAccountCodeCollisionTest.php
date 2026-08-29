<?php

namespace Tests\Feature\Purchasing;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\TreasuryService;
use App\Modules\Purchasing\Services\SupplierService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ترقيم الحسابات الفرعية يتخطّى المحذوف ناعمًا.
 *
 * `accounts.code` فريدٌ **في قاعدة البيانات**، وقيد التفرّد لا يعرف الحذف
 * الناعم: الصفّ المحذوف ما يزال يحتلّ رمزه. بينما كان التوليد يعدّ الأبناء
 * ويفحص التكرار بمُستعلمٍ يُخفي المحذوف — فيرى الرمز شاغرًا وهو مشغول.
 *
 * والأثر لا يظهر عند الحذف بل عند **الإنشاء التالي**: مورّدٌ يُحفظ بلا مشكلة،
 * ثم يفشل الذي يليه بـUniqueConstraintViolation — أي خطأ ٥٠٠ في الشاشة، بلا
 * رسالةٍ تدلّ على السبب.
 */
class LedgerAccountCodeCollisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** حساب مورّدٍ محذوف لا يُعاد رمزه لمورّدٍ جديد. */
    public function test_a_trashed_supplier_account_does_not_free_its_code(): void
    {
        $service = app(SupplierService::class);

        $first = $service->create(['name' => 'مورد أول', 'code' => '1001']);
        $code = $first->glAccount()->firstOrFail()->code;

        // الحساب يُحذف ناعمًا — والرمز يبقى محجوزًا في قيد التفرّد.
        Account::where('code', $code)->firstOrFail()->delete();

        $second = $service->create(['name' => 'مورد ثانٍ', 'code' => '1002']);

        $this->assertNotSame($code, $second->glAccount()->firstOrFail()->code);
    }

    /** وكذلك حساب الخزينة — نفس نمط الترقيم، نفس الثغرة. */
    public function test_a_trashed_treasury_account_does_not_free_its_code(): void
    {
        $service = app(TreasuryService::class);

        $first = $service->create(['code' => 'TR-A', 'name' => 'خزينة أولى', 'type' => 'cash']);
        $code = Account::findOrFail($first->gl_account_id)->code;

        Account::whereKey($first->gl_account_id)->firstOrFail()->delete();

        $second = $service->create(['code' => 'TR-B', 'name' => 'خزينة ثانية', 'type' => 'cash']);

        $this->assertNotSame($code, Account::findOrFail($second->gl_account_id)->code);
    }

    /** والرمز المُولَّد يبقى فريدًا على مستوى الجدول كلّه لا الظاهر منه. */
    public function test_generated_codes_stay_unique_across_trashed_rows(): void
    {
        $service = app(SupplierService::class);
        $codes = [];

        foreach (range(1, 4) as $i) {
            $supplier = $service->create(['name' => "مورد {$i}", 'code' => (string) (2000 + $i)]);
            $account = $supplier->glAccount()->firstOrFail();
            $codes[] = $account->code;

            if ($i % 2 === 0) {
                $account->delete(); // يُحذف نصفُها ناعمًا أثناء التسلسل.
            }
        }

        $this->assertSame($codes, array_unique($codes));
    }

    /** ولا يُترك للمورّد الواحد أكثر من حساب حين يُعاد حفظه. */
    public function test_an_existing_supplier_keeps_its_account(): void
    {
        $service = app(SupplierService::class);
        $supplier = $service->create(['name' => 'مورد ثابت', 'code' => '3001']);
        $code = $supplier->glAccount()->firstOrFail()->code;

        $service->ensureLedgerAccount($supplier->fresh());

        $this->assertSame($code, $supplier->fresh()->glAccount()->firstOrFail()->code);
    }
}
