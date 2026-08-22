<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تصحيح عمولات المسوّقين المحسوبة على لقطة جملةٍ صفر.
 *
 * البنود القديمة على منتجٍ ذي مقاسات جُمّدت لقطتها صفرًا، فهبط أساس العمولة
 * إلى **التكلفة** بدل سعر الجملة — والتكلفة أدنى، فالهامش أوسع والعمولة أعلى
 * مما تستحقّ.
 *
 * والتصحيح **بحركةٍ جديدة لا بتعديل القديمة**: الدفتر يمنع تغيير المبالغ بعد
 * الإنشاء، ودفترٌ يُعدَّل بأثرٍ رجعيّ لا يُسأل عمّا جرى.
 */
class WholesaleSnapshotRepairTest extends TestCase
{
    use RefreshDatabase;

    private User $affiliate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->affiliate = User::factory()->create(['branch_id' => Branch::default()->id]);
        $this->affiliate->assignRole('affiliate');
    }

    /**
     * بندٌ بلقطةٍ صفر وعمولةٍ محسوبة على التكلفة.
     *
     * بيع 100 · جملة 70 · تكلفة 40 ⇒ العمولة المسجَّلة 60 (على التكلفة)
     * والصحيحة 30 (على الجملة).
     */
    private function affectedItem(float $productWholesale = 70): OrderItem
    {
        $product = Product::factory()->create(['retail_price' => 100, 'wholesale_price' => $productWholesale]);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id, 'sku' => 'V-REPAIR-'.uniqid(),
            'is_default' => false, 'retail_price' => 100,
            'wholesale_price' => null, 'average_cost' => 40,
        ]);

        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'affiliate_id' => $this->affiliate->id,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0,
            'line_total' => 100,
            'wholesale_cost_snapshot' => 40,
            'wholesale_price_snapshot' => 0,   // الخطأ المُجمَّد
        ]);

        // الاستحقاق كما وقع وقتها: الهامش على التكلفة (100 − 40) = 60.
        CommissionEntry::create([
            'earner_type' => 'affiliate', 'earner_id' => $this->affiliate->id,
            'order_id' => $order->id, 'order_item_id' => $item->id, 'variant_id' => $variant->id,
            'entry_type' => 'accrual', 'basis' => 60, 'rate' => 1.0, 'amount' => 60,
            'wholesale_cost_snapshot' => 40,
            'rule_snapshot' => ['method' => 'margin', 'rate' => 1.0, 'default' => true],
            'state' => 'pending',
        ]);

        return $item->fresh(['variant.product']);
    }

    private function service(): CommissionService
    {
        return app(CommissionService::class);
    }

    /** الفارق يُحسب صحيحًا: 60 كانت، 30 تصير، −30 الفرق. */
    public function test_it_computes_the_difference_against_the_real_wholesale(): void
    {
        $changes = $this->service()->correctWholesaleSnapshot($this->affectedItem(), null, apply: false);

        $this->assertCount(1, $changes);
        $this->assertSame(60.0, $changes[0]['was']);
        $this->assertSame(30.0, $changes[0]['now']);
        $this->assertSame(-30.0, $changes[0]['delta']);
    }

    /** والعرض وحده لا يكتب شيئًا. */
    public function test_a_dry_run_writes_nothing(): void
    {
        $item = $this->affectedItem();

        $this->service()->correctWholesaleSnapshot($item, null, apply: false);

        $this->assertSame(0, CommissionEntry::where('entry_type', 'adjustment')->count());
        $this->assertEqualsWithDelta(0, (float) $item->fresh()->wholesale_price_snapshot, 0.01);
    }

    /**
     * والتنفيذ يضيف حركة تعديلٍ ولا يمسّ الأصل.
     *
     * الدفتر يمنع تغيير المبالغ، والأصل يجب أن يبقى ظاهرًا بجانب تصحيحه.
     */
    public function test_applying_adds_an_adjustment_and_leaves_the_original_intact(): void
    {
        $item = $this->affectedItem();
        $original = CommissionEntry::where('entry_type', 'accrual')->firstOrFail();

        $this->service()->correctWholesaleSnapshot($item, null);

        $adjustment = CommissionEntry::where('entry_type', 'adjustment')->firstOrFail();

        $this->assertEqualsWithDelta(-30, (float) $adjustment->amount, 0.01);
        $this->assertSame($original->id, $adjustment->adjusts_entry_id);
        $this->assertEqualsWithDelta(60, (float) $original->fresh()->amount, 0.01);
    }

    /** والرصيد الصافي يصير 30 — الاستحقاق ناقص التصحيح. */
    public function test_the_net_ledger_lands_on_the_correct_amount(): void
    {
        $this->service()->correctWholesaleSnapshot($this->affectedItem(), null);

        $net = (float) CommissionEntry::where('earner_id', $this->affiliate->id)->sum('amount');

        $this->assertEqualsWithDelta(30, $net, 0.01);
    }

    /** واللقطة تُصحَّح مع الحركة، فلا تُنتج إعادةُ الاحتساب الخطأ نفسه. */
    public function test_the_item_snapshot_is_corrected_too(): void
    {
        $item = $this->affectedItem();

        $this->service()->correctWholesaleSnapshot($item, null);

        $this->assertEqualsWithDelta(70, (float) $item->fresh()->wholesale_price_snapshot, 0.01);
    }

    /**
     * والحركة المدفوعة تُصحَّح كاسترداد `eligible` لا تُسحب من أحد.
     *
     * المال خرج فعلًا؛ فالتصحيح يُسجَّل رصيدًا سالبًا يُخصم من مستحقٍّ قادم،
     * لا مطالبةً آليّة بردّ ما استُلم.
     */
    public function test_a_paid_entry_is_corrected_as_a_negative_eligible_refund(): void
    {
        $item = $this->affectedItem();
        CommissionEntry::where('entry_type', 'accrual')->firstOrFail()->forceFill(['state' => 'paid'])->save();

        $this->service()->correctWholesaleSnapshot($item, null);

        $adjustment = CommissionEntry::where('entry_type', 'adjustment')->firstOrFail();

        $this->assertSame('eligible', $adjustment->state);
    }

    /** ولا يُصحَّح ما لا مرجع له: صنفٌ بلا سعر جملةٍ في الموضعين. */
    public function test_an_item_with_no_wholesale_anywhere_is_left_alone(): void
    {
        $changes = $this->service()->correctWholesaleSnapshot($this->affectedItem(productWholesale: 0), null);

        $this->assertSame([], $changes);
        $this->assertSame(0, CommissionEntry::where('entry_type', 'adjustment')->count());
    }

    /** ولا تُمسّ العمولة الثابتة — لا تتعلّق بالهامش أصلًا. */
    public function test_a_fixed_rule_commission_is_never_touched(): void
    {
        $item = $this->affectedItem();
        CommissionEntry::where('entry_type', 'accrual')->firstOrFail()
            ->forceFill(['rule_snapshot' => ['method' => 'fixed', 'amount' => 60]])->save();

        $changes = $this->service()->correctWholesaleSnapshot($item, null);

        $this->assertSame([], $changes);
    }

    /**
     * وتشغيلُه مرّتين لا يضاعف التصحيح.
     *
     * الأمر يُشغَّل أكثر من مرّة بطبيعته — عرضٌ، ثم تنفيذ، ثم تحقّق — وتصحيحٌ
     * يتكرّر يخصم الفارق من المسوّق مرّتين.
     */
    public function test_running_it_twice_does_not_double_correct(): void
    {
        $item = $this->affectedItem();

        $this->service()->correctWholesaleSnapshot($item, null);
        $this->service()->correctWholesaleSnapshot($item->fresh(['variant.product']), null);

        $this->assertSame(1, CommissionEntry::where('entry_type', 'adjustment')->count());
        $this->assertEqualsWithDelta(
            30,
            (float) CommissionEntry::where('earner_id', $this->affiliate->id)->sum('amount'),
            0.01,
        );
    }

    /**
     * وبندٌ عليه تعديلٌ سابق يُترك للمراجعة اليدوية.
     *
     * تعديل المرتجع حُسب نسبةً من مبلغٍ خاطئ، وتصحيحُه آليًّا يحتاج افتراضًا
     * عن ترتيب الحركات لا يصحّ أن يُتَّخذ بلا إنسان.
     */
    public function test_an_item_with_a_prior_adjustment_is_left_for_manual_review(): void
    {
        $item = $this->affectedItem();
        $original = CommissionEntry::where('entry_type', 'accrual')->firstOrFail();

        CommissionEntry::create([
            'earner_type' => 'affiliate', 'earner_id' => $this->affiliate->id,
            'order_id' => $original->order_id, 'order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'entry_type' => 'adjustment', 'basis' => -30, 'rate' => 1.0, 'amount' => -30,
            'adjusts_entry_id' => $original->id,
            'rule_snapshot' => ['method' => 'margin'], 'state' => 'pending',
        ]);

        $changes = $this->service()->correctWholesaleSnapshot($item, null);

        $this->assertSame('has_prior_adjustment', $changes[0]['skipped']);
        $this->assertSame(1, CommissionEntry::where('entry_type', 'adjustment')->count());
    }

    // ────────── الأمر ──────────

    /** الأمر بلا `--apply` يعرض ولا يكتب. */
    public function test_the_command_reports_without_writing(): void
    {
        $this->affectedItem();

        $this->artisan('commissions:repair-wholesale-snapshots')
            ->expectsOutputToContain('عرضٌ فقط')
            ->assertSuccessful();

        $this->assertSame(0, CommissionEntry::where('entry_type', 'adjustment')->count());
    }

    /** ومع `--apply` ينفّذ. */
    public function test_the_command_applies_when_told_to(): void
    {
        $this->affectedItem();

        $this->artisan('commissions:repair-wholesale-snapshots', ['--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, CommissionEntry::where('entry_type', 'adjustment')->count());
    }

    /** ويسكت حين لا شيء متأثّر. */
    public function test_the_command_is_quiet_when_nothing_is_affected(): void
    {
        $this->artisan('commissions:repair-wholesale-snapshots')
            ->expectsOutputToContain('لا بنود متأثّرة')
            ->assertSuccessful();
    }
}
