<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بيان أساس الاحتساب أسفل تقارير المبيعات.
 *
 * التقارير الثلاثة تُحتسب الآن على أساسٍ واحد — سعر بيع البضاعة بعد الخصم، بلا
 * توصيل ولا ضريبة ولا عمولات — والبيان يقول ذلك صراحةً تحت كل صفحة كي يُقرأ
 * الرقم على أساسه.
 */
class SalesReportBasisNoteTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $reports = [
        'admin.reports.sales.by_customer',
        'admin.reports.sales.by_product',
        'admin.reports.sales.by_employee',
        'admin.reports.sales.by_affiliate',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);
    }

    public function test_every_sales_report_states_the_same_basis(): void
    {
        foreach ($this->reports as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee(__('أساس احتساب هذا التقرير'), false)
                ->assertSee(__('سعر بيع البضاعة بعد الخصم'), false)
                ->assertSee(__('رسوم التوصيل'), false)
                ->assertSee(__('عمولات الموظفين والمسوّقين'), false);
        }
    }

    public function test_the_customer_and_product_reports_declare_that_they_match(): void
    {
        $this->get(route('admin.reports.sales.by_customer'))
            ->assertOk()
            ->assertSee(__('المبيعات حسب موظف المبيعات'), false);

        $this->get(route('admin.reports.sales.by_product'))
            ->assertOk()
            ->assertSee(__('إجمالي الأسفل هو عدد الطلبات المختلفة لا جمع العمود'), false);
    }

    public function test_the_note_survives_printing(): void
    {
        // الورقة المطبوعة تحتاج التفسير أكثر من الشاشة، فلا يحمل البيان
        // `report-no-print` الذي يُخفي شريط الخيارات.
        $html = $this->get(route('admin.reports.sales.by_product'))->assertOk()->getContent();

        $notePosition = mb_strpos($html, __('أساس احتساب هذا التقرير'));
        $this->assertNotFalse($notePosition);

        // آخر ظهور لصنف الإخفاء يسبق البيان (شريط الخيارات أعلى الصفحة).
        $lastNoPrint = mb_strrpos($html, 'report-no-print');
        $this->assertLessThan($notePosition, $lastNoPrint);
    }
}
