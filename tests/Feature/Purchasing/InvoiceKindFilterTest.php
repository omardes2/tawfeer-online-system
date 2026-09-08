<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Purchasing\Models\ImportShipment;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\ImportShipmentService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * فلترة فواتير الشراء بالتصنيف: بضاعة · شحن بحري · تخليص · عمولة · نقل داخلي.
 *
 * ## لماذا قائمةٌ واحدة
 *
 * التصنيف عمودان في المخطّط: `kind` يفصل البضاعة عن المصاريف، و`expense_category`
 * يفصل المصاريف بعضها عن بعض. لكنه في ذهن المستخدم سؤالٌ واحد — أيّ نوعٍ من
 * الفواتير أريد؟ فقائمتان تُجبرانه على معرفة أن «شحن بحري» يسكن عمودًا آخر غير
 * «بضاعة»، وهي معرفةٌ لا تلزمه.
 *
 * ## ويُصفّي الصفحة كلّها
 *
 * كالشحنة تمامًا: بطاقةٌ تعدّ كل الفواتير فوق جدولٍ يعرض التخليص وحده تُقرأ على
 * أنها أرقامه. ويتراكب مع فلتر الشحنة — «تخليص هذا الكونتينر» سؤالٌ قائم.
 */
class InvoiceKindFilterTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $service;

    private Supplier $supplier;

    private ProductVariant $variant;

    private ImportShipment $shipment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->service = app(PurchaseInvoiceService::class);
        $this->supplier = Supplier::factory()->create();
        $this->variant = ProductVariant::factory()->create();
        $this->shipment = app(ImportShipmentService::class)->create(['reference' => 'TEMU7550473']);
    }

    private function goods(?ImportShipment $shipment = null): PurchaseInvoice
    {
        return $this->service->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => $shipment?->id,
        ], [['variant_id' => $this->variant->id, 'qty' => 10, 'unit_cost' => 25]]);
    }

    private function expense(string $category, ?ImportShipment $shipment = null): PurchaseInvoice
    {
        return $this->service->createAndPost([
            'supplier_id' => $this->supplier->id,
            'invoice_date' => now()->toDateString(),
            'import_shipment_id' => ($shipment ?? $this->shipment)->id,
            'kind' => 'expenses',
            'expense_category' => $category,
        ], [['description' => 'مصروف', 'qty' => 1, 'unit_cost' => 500]]);
    }

    /** **«بضاعة» تعرض فواتير البضاعة وحدها.** */
    public function test_filtering_by_goods_excludes_expenses(): void
    {
        $goods = $this->goods();
        $freight = $this->expense('sea_freight');

        $this->get(route('admin.purchasing.invoices.index', ['kind' => 'goods']))
            ->assertOk()
            ->assertSee($goods->number)
            ->assertDontSee($freight->number);
    }

    /** **وكل تصنيف مصاريف يعزل نفسه.** */
    public function test_each_expense_category_filters_on_its_own(): void
    {
        $freight = $this->expense('sea_freight');
        $customs = $this->expense('customs');
        $commission = $this->expense('commission');
        $inland = $this->expense('inland');

        $cases = [
            'sea_freight' => [$freight, [$customs, $commission, $inland]],
            'customs' => [$customs, [$freight, $commission, $inland]],
            'commission' => [$commission, [$freight, $customs, $inland]],
            'inland' => [$inland, [$freight, $customs, $commission]],
        ];

        foreach ($cases as $key => [$wanted, $others]) {
            $response = $this->get(route('admin.purchasing.invoices.index', ['kind' => $key]))
                ->assertOk()
                ->assertSee($wanted->number);

            foreach ($others as $other) {
                $response->assertDontSee($other->number);
            }
        }
    }

    /** **والبطاقات تتبع الفلتر** — عددٌ يخالف الجدول يُقرأ عطبًا. */
    public function test_the_cards_follow_the_filter(): void
    {
        $this->goods();
        $this->expense('customs');
        $this->expense('customs');

        $this->assertSame(3, $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()->viewData('totalCount'));

        $this->assertSame(2, $this->get(route('admin.purchasing.invoices.index', ['kind' => 'customs']))
            ->assertOk()->viewData('totalCount'));
    }

    /** **ويتراكب مع فلتر الشحنة** — «تخليص هذا الكونتينر» سؤالٌ قائم. */
    public function test_it_composes_with_the_shipment_filter(): void
    {
        $other = app(ImportShipmentService::class)->create(['reference' => 'MSCU1112223']);

        $wanted = $this->expense('customs', $this->shipment);
        $elsewhere = $this->expense('customs', $other);
        $siblingKind = $this->expense('sea_freight', $this->shipment);

        $this->get(route('admin.purchasing.invoices.index', [
            'shipment' => $this->shipment->id, 'kind' => 'customs',
        ]))
            ->assertOk()
            ->assertSee($wanted->number)
            ->assertDontSee($elsewhere->number)
            ->assertDontSee($siblingKind->number);
    }

    /** ويُلازم تبويب الحالة كذلك. */
    public function test_it_composes_with_the_status_tabs(): void
    {
        $customs = $this->expense('customs');
        $goods = $this->goods();

        $this->get(route('admin.purchasing.invoices.index', ['kind' => 'customs', 'status' => 'posted']))
            ->assertOk()
            ->assertSee($customs->number)
            ->assertDontSee($goods->number);
    }

    /** وتصنيفٌ لا وجود له يُتجاهَل بدل أن يُفرغ الصفحة. */
    public function test_an_unknown_kind_is_ignored(): void
    {
        $goods = $this->goods();

        $response = $this->get(route('admin.purchasing.invoices.index', ['kind' => 'nonsense']))
            ->assertOk()
            ->assertSee($goods->number);

        $this->assertNull($response->viewData('activeKind'));
    }

    /** والقائمة تحمل التصنيفات الخمسة والبضاعة. */
    public function test_the_picker_lists_every_kind(): void
    {
        $this->get(route('admin.purchasing.invoices.index'))
            ->assertOk()
            ->assertSee(__('بضاعة'), false)
            ->assertSee(__('شحن بحري'), false)
            ->assertSee(__('تخليص وجمارك'), false)
            ->assertSee(__('عمولة مكتب'), false)
            ->assertSee(__('نقل داخلي'), false);
    }
}
