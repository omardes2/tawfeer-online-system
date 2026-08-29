<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * فحص أسعار شراء المسوّق.
 *
 * المتوقَّع لكل بند: **سعر قائمته إن كان الصنف فيها، وإلّا سعر الجملة** —
 * وهو ترتيب `PriceListService` نفسه. والفحص يقرأ ولا يكتب: أمرٌ يمسّ مستحقّات
 * شخصٍ بعينه يُقرأ أوّلًا ويُقرَّر بعده.
 */
class AuditEarnerPricesTest extends TestCase
{
    use RefreshDatabase;

    private User $affiliate;

    private PriceList $list;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->list = PriceList::create(['name' => 'قائمة سائد', 'is_active' => true]);

        $this->affiliate = User::factory()->create([
            'name' => 'سائد شاهين',
            'branch_id' => Branch::default()->id,
            'price_list_id' => $this->list->id,
        ]);
        $this->affiliate->assignRole('affiliate');
    }

    private function product(string $name, float $wholesale, float $retail = 100): Product
    {
        return Product::factory()->create([
            'name' => $name, 'retail_price' => $retail, 'wholesale_price' => $wholesale,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);
    }

    /** سعرٌ مخصّص لصنفٍ في قائمة سائد. */
    private function listPrice(Product $product, float $price): void
    {
        PriceListItem::create([
            'price_list_id' => $this->list->id,
            'variant_id' => $product->defaultVariant->id,
            'price' => $price,
        ]);
    }

    /** بندُ طلبٍ لسائد بلقطة سعر شراءٍ مُعطاة. */
    private function item(Product $product, float $frozen, float $price = 100, float $qty = 1): OrderItem
    {
        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'affiliate_id' => $this->affiliate->id,
            'status' => 'confirmed',
        ]);

        $id = DB::table('order_items')->insertGetId([
            'order_id' => $order->id,
            'variant_id' => $product->defaultVariant->id,
            'qty' => $qty,
            'unit_price' => $price,
            'discount' => 0,
            'line_total' => $qty * $price,
            'wholesale_price_snapshot' => $frozen,
            'wholesale_cost_snapshot' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return OrderItem::findOrFail($id);
    }

    /**
     * تشغيل الفحص وإعادة نصّه.
     *
     * `Artisan::call` لا `$this->artisan()`: الثاني يُمسك المُخرَج في كائنٍ
     * مؤجَّل ويترك `Artisan::output()` فارغًا — وفحصُ نصٍّ فارغ ينجح صامتًا
     * على كل شيء.
     */
    private function audit(): string
    {
        $code = Artisan::call('commissions:audit-earner-prices', ['user' => (string) $this->affiliate->id]);

        $this->assertSame(0, $code);

        return Artisan::output();
    }

    // ────────── الحكم ──────────

    /** بندٌ مُجمَّد بسعر قائمته الصحيح لا اختلاف فيه. */
    public function test_an_item_frozen_at_its_list_price_is_clean(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($product, 65);
        $this->item($product, frozen: 65);

        $this->assertStringContainsString('لا اختلاف', $this->audit());
    }

    /** وبندٌ لصنفٍ خارج القائمة يُقاس على سعر الجملة. */
    public function test_an_item_outside_the_list_is_measured_against_wholesale(): void
    {
        $product = $this->product('مكنسة', wholesale: 80);
        $this->item($product, frozen: 80);

        $this->assertStringContainsString('لا اختلاف', $this->audit());
    }

    /**
     * **والصنف الذي له سعرٌ مخصّص وجُمّد بسعر الجملة يُكشَف.**
     *
     * هو الخلل الذي يقع فعلًا: القائمة تُسنَد بعد أن يكون المسوّق قد باع،
     * فبنودُه القديمة مُجمَّدة على الجملة العامّة لا على ما يشتري به.
     */
    public function test_it_catches_a_list_item_frozen_at_wholesale(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($product, 65);
        $this->item($product, frozen: 80, price: 100, qty: 2);

        $output = $this->audit();

        $this->assertStringContainsString('البنود المختلفة', $output);
        // فرقُ الوحدة ١٥ لصالح المسوّق، وبكميّة ٢ صار ٣٠.
        $this->assertStringContainsString('30.00', $output);
    }

    /** ويُميّز مصدر السعر في كل صفّ. */
    public function test_it_names_the_price_source(): void
    {
        $withList = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($withList, 65);
        $this->item($withList, frozen: 90);

        $noList = $this->product('مكنسة', wholesale: 50);
        $this->item($noList, frozen: 90);

        $output = $this->audit();

        $this->assertStringContainsString('قائمته', $output);
        $this->assertStringContainsString('الجملة', $output);
    }

    /**
     * **وأثر التصحيح على العمودين متساوٍ ومتعاكس.**
     *
     * سعر البيع والتكلفة الفعلية لم يمسّهما شيء، فالربح الكلّي ثابت — يتحرّك
     * **موضعُ الخطّ** بين المسوّق والشركة لا مقداره.
     */
    public function test_the_two_columns_move_by_the_same_amount_in_opposite_directions(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($product, 65);
        $this->item($product, frozen: 80, qty: 2);

        $output = $this->audit();

        $this->assertStringContainsString('+30.00', $output);
        $this->assertStringContainsString('-30.00', $output);
        // ومجموعهما صفر.
        $this->assertStringContainsString('0.00', $output);
    }

    /**
     * **ولقطةٌ صفرٌ لا تعني عمولةً على صفر.**
     *
     * الحساب يرتدّ إلى سعر جملة الصنف، فالبند مُسعَّرٌ بـ٨٠ لا بصفر. وقياسُ
     * الفرق على الصفر يقلب إشارة الأثر ويجعل مسوّقًا يستحقّ زيادةً يبدو مدينًا
     * بها — وهو ما وقع فعلًا في أوّل تشغيلٍ لهذا الأمر.
     */
    public function test_a_zero_snapshot_falls_back_to_the_wholesale_price(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($product, 65);
        $this->item($product, frozen: 0, price: 100);

        $output = $this->audit();

        // يُعدّ في الملخّص لأنه بيانٌ ناقص…
        $this->assertStringContainsString('بلقطةٍ صفرٍ', $output);
        // …لكنّ الفرق يُقاس على ٨٠ لا على صفر: ٨٠ − ٦٥ = ١٥.
        $this->assertStringContainsString('15.00', $output);
        $this->assertStringNotContainsString('65.00 | 65.00', $output);
    }

    /** ولقطةٌ صفرٌ بلا سعر جملةٍ ترتدّ إلى التكلفة — آخر الاحتياط. */
    public function test_a_zero_snapshot_without_wholesale_falls_back_to_cost(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 0);
        $product->defaultVariant->forceFill(['average_cost' => 40])->save();
        $this->listPrice($product, 65);
        $this->item($product, frozen: 0, price: 100);

        // ٤٠ تكلفةً مقابل ٦٥ متوقَّعًا ⇒ فرق الوحدة ٢٥.
        $this->assertStringContainsString('25.00', $this->audit());
    }

    // ────────── تفكيك فاتورة ──────────

    /**
     * **`--order` يعرض الاشتقاق كاملًا: من سعر البيع إلى العمولة.**
     *
     * الفحص العامّ يقول «هذا البند مختلف»، وهذا يقول **لماذا** — فيُرى أين
     * انكسر الرقم بدل تخمينه.
     */
    public function test_it_explains_a_single_order_line_by_line(): void
    {
        $product = $this->product('جهاز تعطير', wholesale: 100);
        $this->listPrice($product, 100);

        // بِيع بـ٢٢٠ وجُمّد شراؤه بـ١٦٣ خطأً ⇒ العمولة ٥٧ بدل ١٢٠.
        $item = $this->item($product, frozen: 163, price: 220);

        CommissionEntry::create([
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'earner_id' => $this->affiliate->id,
            'earner_type' => 'affiliate',
            'entry_type' => 'accrual',
            'state' => 'eligible',
            'basis' => 57,
            'rate' => 1,
            'amount' => 57,
        ]);

        $output = $this->explain($item->order->number);

        $this->assertStringContainsString('جهاز تعطير', $output);
        $this->assertStringContainsString('57.00', $output);   // العمولة الآن
        $this->assertStringContainsString('120.00', $output);  // ما يجب أن تكون
        $this->assertStringContainsString('63.00', $output);   // الفرق
    }

    /** ويقبل رقم التتبّع كما يقبل رقم الطلب. */
    public function test_it_accepts_a_tracking_number(): void
    {
        $product = $this->product('جهاز تعطير', wholesale: 100);
        $item = $this->item($product, frozen: 100, price: 220);
        $item->order->update(['tracking_number' => '7471455']);

        $this->assertStringContainsString('7471455', $this->explain('7471455'));
    }

    /** وطلبٌ ليس له يُرفض بوضوح. */
    public function test_an_unknown_order_is_reported(): void
    {
        $code = Artisan::call('commissions:audit-earner-prices', [
            'user' => (string) $this->affiliate->id,
            '--order' => 'SO-LA-YUJAD',
        ]);

        $this->assertSame(1, $code);
    }

    /** تفكيك طلبٍ بعينه — `Artisan::call` لا `$this->artisan()` كما في `audit()`. */
    private function explain(string $key): string
    {
        $code = Artisan::call('commissions:audit-earner-prices', [
            'user' => (string) $this->affiliate->id,
            '--order' => $key,
        ]);

        $this->assertSame(0, $code);

        return Artisan::output();
    }

    // ────────── الحدود ──────────

    /**
     * **ولا يكتب شيئًا.**
     *
     * الفحص يُقرأ ثم يُقرَّر. وكتابةٌ خفيّة تجعل «العرض» تنفيذًا.
     */
    public function test_it_writes_nothing(): void
    {
        $product = $this->product('عطر سمارت', wholesale: 80);
        $this->listPrice($product, 65);
        $item = $this->item($product, frozen: 80);

        $this->audit();

        $this->assertEqualsWithDelta(80.0, (float) $item->fresh()->wholesale_price_snapshot, 0.01);
    }

    /** ومسوّقٌ بلا قائمةٍ يُقاس كلُّ بنوده على الجملة. */
    public function test_an_earner_without_a_list_is_measured_against_wholesale(): void
    {
        $this->affiliate->update(['price_list_id' => null]);

        $product = $this->product('مكنسة', wholesale: 50);
        $this->item($product, frozen: 70);

        $output = $this->audit();

        $this->assertStringContainsString('لا قائمة مُسنَدة', $output);
        $this->assertStringContainsString('البنود المختلفة', $output);
    }

    /** وطلبات غيره لا تدخل الفحص. */
    public function test_another_affiliates_orders_are_excluded(): void
    {
        $other = User::factory()->create(['branch_id' => Branch::default()->id]);
        $product = $this->product('مكنسة', wholesale: 50);

        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::firstOrFail()->id,
            'affiliate_id' => $other->id,
            'status' => 'confirmed',
        ]);

        DB::table('order_items')->insert([
            'order_id' => $order->id,
            'variant_id' => $product->defaultVariant->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100,
            'wholesale_price_snapshot' => 999, 'wholesale_cost_snapshot' => 40,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertStringContainsString('لا بنود لهذا المسوّق', $this->audit());
    }

    /** والملغاة خارجه — لم تُبَع. */
    public function test_cancelled_orders_are_excluded(): void
    {
        $product = $this->product('مكنسة', wholesale: 50);
        $item = $this->item($product, frozen: 70);
        $item->order->update(['status' => 'cancelled']);

        $this->assertStringContainsString('لا بنود لهذا المسوّق', $this->audit());
    }
}
