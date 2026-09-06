<?php

namespace Tests\Feature\Purchasing;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAttribute;
use App\Modules\Catalog\Models\ProductAttributeValue;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Models\InventoryStock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use App\Modules\Purchasing\Models\Supplier;
use App\Modules\Purchasing\Services\InvoiceVariantSplitService;
use App\Modules\Purchasing\Services\PurchaseInvoiceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * توزيع بند فاتورة الشراء من الصنف المجرَّد على مقاساته.
 *
 * ## الموقف
 *
 * تُدخَل فاتورة على منتجٍ بلا مقاسات، ثم تُنشأ له مقاسات. ومصفوفة المتغيّرات
 * **توزّع ولا تضيف**: تُصفّر رصيد المجرَّد وتنقله للمقاسات — والمخزون بذلك سليم
 * وإجمالي المنتج (وهو مجموع مقاساته) لم يتغيّر.
 *
 * لكن بند الفاتورة يبقى مشيرًا إلى المجرَّد ورصيدُه صفر، فيترتّب أمران:
 *
 * 1. لا تُعدَّل الفاتورة ولا تُعكس: كلاهما يسحب البضاعة أولًا، ولا شيء ليُسحَب.
 * 2. تقارير المشتريات بالمقاس تُظهر الكمية تحت صنفٍ عارٍ لا مقاس له.
 *
 * ## والعلاج وسمٌ لا حركة
 *
 * الكمية موزَّعة أصلًا وصحيحة، فأي حركة مخزون هنا تُضاعف ما هو قائم. تُصحَّح
 * إشارةُ السطر ولا يُلمس رصيد — والمال يبقى بالقرش كما كان، فلا يحتاج القيد
 * إعادة ترحيل.
 */
class InvoiceVariantSplitTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseInvoiceService $invoices;

    private InvoiceVariantSplitService $splitter;

    private Supplier $supplier;

    private Warehouse $warehouse;

    private Product $product;

    private ProductVariant $bare;

    private ?ProductAttribute $attribute = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create(['branch_id' => Branch::default()->id]);
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $this->invoices = app(PurchaseInvoiceService::class);
        $this->splitter = app(InvoiceVariantSplitService::class);
        $this->supplier = Supplier::factory()->create();
        $this->warehouse = Warehouse::where('is_default', true)->first() ?? Warehouse::orderBy('id')->firstOrFail();

        $this->bare = ProductVariant::factory()->create();
        $this->product = $this->bare->product;
    }

    /** مقاسٌ للمنتج نفسه، يحمل قيمة سمة — فيميّزه الفحص عن المجرَّد. */
    private function makeSize(string $label): ProductVariant
    {
        // السمة تُنشأ مرّة للصنف كلّه: قيمُ المقاس تنتمي لسمةٍ واحدة، وسمةٌ لكل
        // قيمة تجعل «M» و«L» محورَي تنويعٍ مستقلَّين لا مقاسين.
        $this->attribute ??= ProductAttribute::factory()->create(['name' => 'المقاس', 'type' => 'select']);

        $value = ProductAttributeValue::factory()->create([
            'attribute_id' => $this->attribute->id,
            'value' => $label,
            'label' => $label,
        ]);

        $variant = ProductVariant::factory()->create([
            'product_id' => $this->product->id,
            'is_default' => false,
        ]);
        $variant->attributeValues()->attach($value->id);

        return $variant->load('attributeValues');
    }

    private function postedInvoice(float $qty = 100, float $cost = 35): PurchaseInvoice
    {
        return $this->invoices->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $this->bare->id, 'qty' => $qty, 'unit_cost' => $cost]],
        );
    }

    /** يُحاكي ما تفعله مصفوفة المتغيّرات: تصفير المجرَّد ونقل كميته للمقاسات. */
    private function distribute(array $shares): void
    {
        $inventory = app(InventoryService::class);

        foreach ($shares as $variantId => $qty) {
            $inventory->adjustIn(ProductVariant::find($variantId), $this->warehouse, $qty, 35, ['reason' => 'variant_stock_set']);
        }

        $inventory->adjustOut($this->bare, $this->warehouse, array_sum($shares), ['reason' => 'variant_stock_set']);
    }

    private function onHand(int $variantId): float
    {
        return (float) InventoryStock::where('variant_id', $variantId)
            ->where('warehouse_id', $this->warehouse->id)->value('on_hand');
    }

    // ────────── الخطّة ──────────

    /** **البند المجرَّد يُكتشف، ويُقترح توزيعه على أرصدة مقاساته.** */
    public function test_it_plans_the_split_from_the_current_stock(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $plan = $this->splitter->plan($invoice->fresh('items'));

        $this->assertCount(1, $plan);
        $this->assertTrue($plan[0]['exact']);
        $this->assertEqualsCanonicalizing(
            [60.0, 40.0],
            array_column($plan[0]['rows'], 'qty'),
        );
    }

    /** والبند الذي يحمل مقاسًا بالفعل لا يُمسّ. */
    public function test_an_item_already_on_a_size_is_ignored(): void
    {
        $m = $this->makeSize('M');
        $invoice = $this->invoices->createAndPost(
            ['supplier_id' => $this->supplier->id, 'invoice_date' => now()->toDateString()],
            [['variant_id' => $m->id, 'qty' => 50, 'unit_cost' => 35]],
        );

        $this->assertSame([], $this->splitter->plan($invoice->fresh('items')));
    }

    /** ومنتجٌ بلا مقاسات أصلًا ليس فيه ما يُوزَّع. */
    public function test_a_product_without_sizes_is_ignored(): void
    {
        $invoice = $this->postedInvoice(100);

        $this->assertSame([], $this->splitter->plan($invoice->fresh('items')));
    }

    /** ومقاساتٌ بلا رصيد لا يُستدلّ منها على توزيع — تُرفض بلا تخمين. */
    public function test_sizes_without_stock_yield_no_guess(): void
    {
        $invoice = $this->postedInvoice(100);
        $this->makeSize('M');

        $plan = $this->splitter->plan($invoice->fresh('items'));

        $this->assertSame([], $plan[0]['rows']);
        $this->assertNotNull($plan[0]['note']);
    }

    // ────────── التنفيذ ──────────

    /** **المخزون لا يُلمس** — وهو شرط العملية كلّها. */
    public function test_applying_does_not_touch_the_stock(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $before = [$this->onHand($m->id), $this->onHand($l->id), $this->onHand($this->bare->id)];

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $this->assertSame($before, [$this->onHand($m->id), $this->onHand($l->id), $this->onHand($this->bare->id)]);
    }

    /** **والبند يصير سطرين، كلٌّ على مقاسه.** */
    public function test_the_item_becomes_one_row_per_size(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $items = $invoice->fresh('items')->items;

        $this->assertCount(2, $items);
        $this->assertEqualsCanonicalizing([$m->id, $l->id], $items->pluck('variant_id')->all());
        $this->assertEqualsCanonicalizing([60.0, 40.0], $items->pluck('qty')->map(fn ($q) => (float) $q)->all());
    }

    /**
     * **والمال لا يتغيّر بالقرش.**
     *
     * مجموع الأسطر الجديدة = السطر الأصلي، وإجمالي الفاتورة كما كان — وإلّا
     * خالف مجموعُ البنود إجماليَّ الفاتورة وسقط القيد.
     */
    public function test_the_money_is_preserved_to_the_agora(): void
    {
        $invoice = $this->postedInvoice(100, 35);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $totalBefore = (float) $invoice->total;
        $lineBefore = (float) $invoice->fresh('items')->items->sum('line_total');

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $fresh = $invoice->fresh('items');

        $this->assertEqualsWithDelta($lineBefore, (float) $fresh->items->sum('line_total'), 0.001);
        $this->assertEqualsWithDelta($totalBefore, (float) $fresh->total, 0.001);
    }

    /** والقسمة التي لا تستقيم يُسنَد باقيها لسطرٍ فلا يضيع قرش. */
    public function test_an_uneven_split_still_sums_exactly(): void
    {
        // 100 على ثلاثة: 33.33 + 33.33 + 33.34 — لا 99.99.
        $invoice = $this->postedInvoice(100, 33.333);
        $a = $this->makeSize('M');
        $b = $this->makeSize('L');
        $c = $this->makeSize('XL');
        $this->distribute([$a->id => 34, $b->id => 33, $c->id => 33]);

        $lineBefore = (float) $invoice->fresh('items')->items->sum('line_total');

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $this->assertEqualsWithDelta($lineBefore, (float) $invoice->fresh('items')->items->sum('line_total'), 0.001);
    }

    /** **وبعده تُعدَّل الفاتورة** — وهو الغرض الأول من العملية. */
    public function test_the_invoice_becomes_editable_again(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $this->assertNotSame([], $this->invoices->stockShortages($invoice->fresh('items')));

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $this->assertSame([], $this->invoices->stockShortages($invoice->fresh('items')));
    }

    /** والعملية مُوقَّعة في سجلّ التدقيق — تغييرُ بندٍ مُرحّل لا يجوز بلا أثر. */
    public function test_it_is_recorded_in_the_audit_log(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $this->splitter->apply($invoice, $this->splitter->plan($invoice->fresh('items')));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'purchase_invoice.variant_split',
            'auditable_id' => $invoice->id,
        ]);
    }

    // ────────── التوزيع الصريح ──────────

    /** توزيعٌ صريح من المستخدم يُقبل حين يطابق مجموعُه الكمية. */
    public function test_an_explicit_split_is_honoured(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $item = $invoice->fresh('items')->items->first();
        $plan = $this->splitter->plan($invoice->fresh('items'), [
            $item->id => [$m->id => 70, $l->id => 30],
        ]);

        $this->assertTrue($plan[0]['exact']);
        $this->assertEqualsCanonicalizing([70.0, 30.0], array_column($plan[0]['rows'], 'qty'));
    }

    /** ويُرفض حين يخالفه — لا يُكتب فرقٌ صامت في فاتورة مُرحّلة. */
    public function test_an_explicit_split_that_misses_the_total_is_refused(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $item = $invoice->fresh('items')->items->first();
        $plan = $this->splitter->plan($invoice->fresh('items'), [
            $item->id => [$m->id => 70, $l->id => 20],   // 90 لا 100
        ]);

        $this->assertSame([], $plan[0]['rows']);
        $this->assertStringContainsString('يخالف كمية البند', $plan[0]['note']);
    }

    // ────────── الأمر ──────────

    /** الأمر يعرض ولا يكتب بلا `--apply`. */
    public function test_the_command_is_read_only_without_apply(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $this->artisan('purchasing:split-invoice-variants', ['invoice' => $invoice->number])
            ->expectsOutputToContain('عرضٌ فقط')
            ->assertSuccessful();

        $this->assertCount(1, $invoice->fresh('items')->items);
    }

    /** ومع `--apply` يكتب. */
    public function test_the_command_writes_with_apply(): void
    {
        $invoice = $this->postedInvoice(100);
        $m = $this->makeSize('M');
        $l = $this->makeSize('L');
        $this->distribute([$m->id => 60, $l->id => 40]);

        $this->artisan('purchasing:split-invoice-variants', ['invoice' => $invoice->number, '--apply' => true])
            ->assertSuccessful();

        $this->assertCount(2, $invoice->fresh('items')->items);
    }
}
