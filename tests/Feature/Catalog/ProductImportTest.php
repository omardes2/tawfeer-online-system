<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * استيراد الأصناف من CSV مع رصيد افتتاحي: إنشاء المنتجات بأسعارها وفئاتها، إدخال
 * الكميات للمستودع، وترحيل قيد واحد متوازن (مدين المخزون / دائن رأس المال).
 */
class ProductImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole('admin');

        return $u;
    }

    /** ملف بترويسة عربية كما يُصدّره Excel (مع BOM). */
    private function csv(string $body): UploadedFile
    {
        $head = "اسم الصنف,الكمية,سعر البيع,سعر الجملة,سعر الشراء,الفئات\n";

        return UploadedFile::fake()->createWithContent('items.csv', "\xEF\xBB\xBF".$head.$body);
    }

    private function balance(string $code): float
    {
        return app(AccountingService::class)->accountBalance(Account::where('code', $code)->firstOrFail());
    }

    public function test_import_creates_products_stock_and_opening_entry(): void
    {
        $inventoryBefore = $this->balance('1200');
        $equityBefore = $this->balance('3010');

        $res = $this->actingAs($this->admin())->post(route('admin.products.import.upload'), [
            'file' => $this->csv("حامل الهاتف,2,60,30,18,الالكترونيات\nعطر 250 ملم,15,30,30,25,عطور\n"),
        ])->assertOk();

        // المنتجات بأسعارها وفئاتها.
        $phone = Product::where('name', 'حامل الهاتف')->firstOrFail();
        $this->assertEqualsWithDelta(60, (float) $phone->retail_price, 0.01);
        $this->assertEqualsWithDelta(30, (float) $phone->wholesale_price, 0.01);
        $this->assertEqualsWithDelta(18, (float) $phone->cost_price, 0.01);
        $this->assertSame('الالكترونيات', Category::find($phone->category_id)?->name);

        // سعر الجملة يصل المتغيّر أيضًا (أساس ربح المسوّق).
        $this->assertEqualsWithDelta(30, (float) $phone->defaultVariant->wholesale_price, 0.01);

        // الكميات دخلت المستودع الافتراضي.
        $warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->assertEqualsWithDelta(2, (float) InventoryStock::where('variant_id', $phone->defaultVariant->id)
            ->where('warehouse_id', $warehouse->id)->value('on_hand'), 0.001);

        // قيد افتتاحي واحد متوازن: (2×18) + (15×25) = 411.
        $this->assertEqualsWithDelta($inventoryBefore + 411, $this->balance('1200'), 0.01);
        $this->assertEqualsWithDelta($equityBefore + 411, $this->balance('3010'), 0.01);

        $this->assertEquals(411.0, $res->viewData('result')['imported']['value']);
        $this->assertEquals(2, $res->viewData('result')['imported']['created']);
    }

    /** المعاينة تحسب ولا تكتب شيئًا. */
    public function test_preview_writes_nothing(): void
    {
        $before = $this->balance('1200');

        $res = $this->actingAs($this->admin())->post(route('admin.products.import.upload'), [
            'file' => $this->csv("صنف معاينة,5,100,70,50,فئة\n"),
            'preview' => '1',
        ])->assertOk();

        $this->assertSame(0, Product::where('name', 'صنف معاينة')->count());
        $this->assertEqualsWithDelta($before, $this->balance('1200'), 0.01);
        $this->assertEquals(250.0, $res->viewData('result')['value']); // 5 × 50
        $this->assertNull($res->viewData('result')['imported']);
    }

    /** المكرّر داخل الملف والموجود مسبقًا يُتخطّيان بأسطر خطأ مفهومة — لا يفشل الاستيراد. */
    public function test_duplicates_are_skipped_with_reasons(): void
    {
        Product::factory()->create(['name' => 'صنف قائم']);

        $res = $this->actingAs($this->admin())->post(route('admin.products.import.upload'), [
            'file' => $this->csv("صنف جديد,1,10,8,5,\nصنف جديد,2,10,8,5,\nصنف قائم,3,10,8,5,\n"),
        ])->assertOk();

        $result = $res->viewData('result');
        $this->assertCount(1, $result['rows']);          // الجديد مرّة واحدة
        $this->assertCount(2, $result['errors']);        // المكرّر + القائم
        $this->assertSame(1, Product::where('name', 'صنف جديد')->count());
    }

    /** أرقام بفواصل آلاف وأرقام عربية تُقرأ صحيحة. */
    public function test_numbers_with_separators_and_arabic_digits_are_parsed(): void
    {
        $res = $this->actingAs($this->admin())->post(route('admin.products.import.upload'), [
            'file' => $this->csv("صنف رقمي,\"1,200\",١٥٠,100,٩٠,\n"),
            'preview' => '1',
        ])->assertOk();

        $row = $res->viewData('result')['rows'][0];
        $this->assertEqualsWithDelta(1200, $row['qty'], 0.001);
        $this->assertEqualsWithDelta(150, $row['retail_price'], 0.01);
        $this->assertEqualsWithDelta(90, $row['cost_price'], 0.01);
    }

    /** ملف بلا الأعمدة الإلزامية يُرفض برسالة واضحة بدل خطأ غامض. */
    public function test_file_without_required_columns_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('bad.csv', "عمود,آخر\nقيمة,قيمة\n");

        $res = $this->actingAs($this->admin())
            ->post(route('admin.products.import.upload'), ['file' => $file])->assertOk();

        $this->assertNull($res->viewData('result'));
        $this->assertStringContainsString('اسم الصنف', $res->viewData('fileError'));
    }
}
