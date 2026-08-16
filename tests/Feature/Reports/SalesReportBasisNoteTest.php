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
 * ثلاثة تقارير تعطي ثلاثة أرقام مختلفة لليوم نفسه: «حسب الزبون» يشمل رسوم
 * التوصيل، و«حسب المنتج» قيمة البضاعة وحدها، و«حسب الموظف» يُسقط الطلبات غير
 * المُسنَدة. كلٌّ صحيحٌ لسؤاله — لكن الرقم العاري يجعل الاختلاف يبدو خللًا.
 */
class SalesReportBasisNoteTest extends TestCase
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

    public function test_the_customer_report_states_that_shipping_is_included(): void
    {
        $this->get(route('admin.reports.sales.by_customer'))
            ->assertOk()
            ->assertSee(__('أساس احتساب هذا التقرير'), false)
            ->assertSee(__('رسوم التوصيل'), false)
            ->assertSee(__('كم حُصِّل من الزبائن؟'), false);
    }

    public function test_the_product_report_states_that_shipping_is_excluded(): void
    {
        $this->get(route('admin.reports.sales.by_product'))
            ->assertOk()
            ->assertSee(__('أساس احتساب هذا التقرير'), false)
            ->assertSee(__('قيمة بنود الأصناف بعد الخصم'), false)
            ->assertSee(__('كم بضاعةً بِعت؟'), false);
    }

    public function test_the_employee_report_warns_that_unassigned_orders_are_dropped(): void
    {
        // الاستثناء الأهمّ: الطلب بلا موظف لا يظهر في صفّ ولا في المجموع.
        $this->get(route('admin.reports.sales.by_employee'))
            ->assertOk()
            ->assertSee(__('أساس احتساب هذا التقرير'), false)
            ->assertSee(__('مجموع هذا التقرير أقلّ من مبيعات الفترة بالضرورة، لأن الطلبات غير المرتبطة ب:person لا تدخله. لمعرفة إجمالي المبيعات راجع «المبيعات حسب المنتج».', ['person' => __('الموظف')]), false);
    }

    public function test_the_affiliate_report_reuses_the_note_with_its_own_wording(): void
    {
        $this->get(route('admin.reports.sales.by_affiliate'))
            ->assertOk()
            ->assertSee(__('الطلبات المرتبطة ب:person فقط', ['person' => __('المسوّق')]), false);
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
