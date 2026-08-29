<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderCollectionService;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تسجيل ما حُصِّل فعلًا من الزبون.
 *
 * شركة التوصيل تُعدّل مبلغ التحصيل قبل التسليم: طلبٌ إجماليّه ٦٤٠ يصل ملصقُه
 * بـ`COD: 620`. والنظام كان يُعلّمه «مدفوعًا بالكامل» لأنه لا يعرف إلّا رقمًا
 * واحدًا — فتقول الفاتورة ٦٤٠ والصندوق يستلم ٦٢٠.
 *
 * والفحص الحاسم ليس «هل حُفظ الرقم؟» بل **«أين وقع الفرق محاسبيًّا؟»**: ترحيلُ
 * البيع يقع على قيمة البضاعة بلا توصيل، فالفرق الذي لا يتجاوز رسوم التوصيل
 * خرج من هامشها لا من الإيراد — ولا قيد له.
 */
class OrderCollectionVarianceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->warehouse = Warehouse::firstOrFail();

        $this->product = Product::factory()->create([
            'name' => 'جهاز تعطير', 'retail_price' => 620, 'wholesale_price' => 400,
            'status' => 'active', 'is_active' => true, 'visibility' => 'visible',
        ]);

        app(InventoryService::class)->openingStock(
            $this->product->defaultVariant, $this->warehouse, 100, 300,
        );
    }

    /** طلبُ توصيلٍ ببضاعةٍ ورسوم — كحالتك: ٦٢٠ بضاعة + ٢٠ توصيل. */
    private function order(float $goods = 620, float $shipping = 20): Order
    {
        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'سفيان',
            'customer_phone' => '0599222344',
            'shipping_address' => 'بيتونيا - رام الله',
            'channel' => 'manual',
            'shipping_total' => $shipping,
        ], [[
            'variant_id' => $this->product->defaultVariant->id,
            'qty' => 1, 'unit_price' => $goods,
        ]], (int) now()->year);

        app(OrderService::class)->fulfillToShipped($order);

        return $order->refresh();
    }

    private function service(): OrderCollectionService
    {
        return app(OrderCollectionService::class);
    }

    /** رصيد حسابٍ من القيود — لا رقمَ محفوظًا يُصدَّق. */
    private function balance(string $code): float
    {
        $account = Account::where('code', $code)->firstOrFail();

        return round((float) JournalLine::where('account_id', $account->id)->sum('debit')
            - (float) JournalLine::where('account_id', $account->id)->sum('credit'), 2);
    }

    // ────────── الفرق الذي لا يمسّ البضاعة ──────────

    /**
     * **٦٤٠ ← ٦٢٠: الفرق رسومُ توصيلٍ لم تُحصَّل — فلا قيد.**
     *
     * ذمّة الطلب مُرحَّلة على قيمة البضاعة (٦٢٠) وقد أُقفلت كاملةً. وتقييدُ
     * الفرق خصمًا يُنقص الإيراد بمبلغِ توصيلٍ لم يكن إيرادًا أصلًا.
     */
    public function test_a_shortfall_within_the_delivery_fee_posts_no_entry(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        $before = $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT);

        $order = $this->service()->record($order, 620, 'الزبون رفض رسوم التوصيل', $this->admin);

        $this->assertEqualsWithDelta(620.0, (float) $order->collected_total, 0.01);
        $this->assertNull($order->collection_entry_id);
        $this->assertEqualsWithDelta($before, $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT), 0.01);
    }

    /** والطلب يُقفل على ما حُصِّل — الفرق خصمٌ مقبول لا دَينٌ مفتوح. */
    public function test_the_order_closes_on_what_was_collected(): void
    {
        $order = $this->service()->record($this->order(), 620, null, $this->admin);

        $this->assertEqualsWithDelta(620.0, (float) $order->amount_paid, 0.01);
        $this->assertSame('partially_paid', $order->payment_status);
        $this->assertEqualsWithDelta(20.0, $this->service()->variance($order), 0.01);
    }

    // ────────── الفرق الذي يمسّ البضاعة ──────────

    /**
     * **٦٤٠ ← ٥٩٠: نقصٌ على البضاعة ٣٠ — يُقيَّد خصمًا.**
     *
     * ما تجاوز رسوم التوصيل نقصٌ حقيقيّ في قيمة ما بِيع، وتركُه بلا قيد يُبقي
     * ذمّةً مفتوحةً على شركة التوصيل لا تُحصَّل أبدًا.
     */
    public function test_a_shortfall_on_the_goods_is_posted_as_a_discount(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        $before = $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT);

        $order = $this->service()->record($order, 590, 'تفاوض المندوب', $this->admin);

        $this->assertNotNull($order->collection_entry_id);
        // ٦٢٠ بضاعة − ٥٩٠ مُحصَّل = ٣٠.
        $this->assertEqualsWithDelta(
            $before + 30.0,
            $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT),
            0.01,
        );
    }

    /** والقيد على النقص في البضاعة لا على كامل الفرق. */
    public function test_the_entry_covers_the_goods_shortfall_only(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        // الفرق الكلّي ٥٠، لكن ٢٠ منه رسومُ توصيل.
        $this->assertEqualsWithDelta(30.0, $this->service()->goodsShortfall($order, 590), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->service()->goodsShortfall($order, 620), 0.01);
        $this->assertEqualsWithDelta(0.0, $this->service()->goodsShortfall($order, 640), 0.01);
    }

    // ────────── إعادة التسجيل ──────────

    /**
     * **إعادة التسجيل تُصحّح ولا تُراكم.**
     *
     * الرقم يُدخَل بيدٍ من ملصقٍ فيُخطئ ويُعاد. فقيدُ الفرق السابق يُعكس قبل
     * كتابة الجديد — وإلّا تراكمت خصومٌ على طلبٍ واحد.
     */
    public function test_recording_twice_leaves_one_net_effect(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        $before = $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT);

        $order = $this->service()->record($order, 590, null, $this->admin);
        $order = $this->service()->record($order, 570, 'تصحيح', $this->admin);

        // الأثر الصافي ٥٠ (٦٢٠ − ٥٧٠) لا ٣٠ + ٥٠.
        $this->assertEqualsWithDelta(
            $before + 50.0,
            $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT),
            0.01,
        );
    }

    /** والإلغاء يُعيد الطلب إلى ما كان بلا أثرٍ في الدفتر. */
    public function test_clearing_undoes_the_entry(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        $before = $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT);

        $order = $this->service()->record($order, 590, null, $this->admin);
        $order = $this->service()->clear($order, $this->admin);

        $this->assertNull($order->collected_total);
        $this->assertNull($order->collection_entry_id);
        $this->assertEqualsWithDelta($before, $this->balance(OrderCollectionService::DISCOUNT_ACCOUNT), 0.01);
    }

    // ────────── الحدود ──────────

    /** والزيادة عن الإجمالي مرفوضة — المندوب لا يقبض أكثر ممّا على الملصق. */
    public function test_collecting_more_than_the_total_is_refused(): void
    {
        $order = $this->order(goods: 620, shipping: 20);

        $this->expectException(ValidationException::class);
        $this->service()->record($order, 700, null, $this->admin);
    }

    /** والسالب مرفوض. */
    public function test_a_negative_amount_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->record($this->order(), -5, null, $this->admin);
    }

    /** والملغى لا يُسجَّل عليه تحصيل. */
    public function test_a_cancelled_order_is_refused(): void
    {
        $order = $this->order();
        $order->update(['status' => 'cancelled']);

        $this->expectException(ValidationException::class);
        $this->service()->record($order->refresh(), 620, null, $this->admin);
    }

    /** والتحصيل الكامل يُقفل الطلب مدفوعًا بلا فرق. */
    public function test_full_collection_closes_the_order_with_no_variance(): void
    {
        $order = $this->service()->record($this->order(goods: 620, shipping: 20), 640, null, $this->admin);

        $this->assertSame('paid', $order->payment_status);
        $this->assertEqualsWithDelta(0.0, $this->service()->variance($order), 0.01);
        $this->assertNull($order->collection_entry_id);
    }

    /** ومن سجّل ومتى يبقى مكتوبًا — الرقم يُدخَل بيد، فيُسأل عنه. */
    public function test_it_records_who_and_when(): void
    {
        $order = $this->service()->record($this->order(), 620, 'ملاحظة', $this->admin);

        $this->assertSame($this->admin->id, $order->collection_recorded_by);
        $this->assertNotNull($order->collection_recorded_at);
        $this->assertSame('ملاحظة', $order->collection_note);
    }
}
