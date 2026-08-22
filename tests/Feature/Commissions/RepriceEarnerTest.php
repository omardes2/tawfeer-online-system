<?php

namespace Tests\Feature\Commissions;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Commissions\Models\CommissionEntry;
use App\Modules\Commissions\Models\CommissionTransition;
use App\Modules\Commissions\Services\CommissionService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Models\OrderItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * إعادة احتساب أرباح مسوّقٍ واحد على قائمة أسعاره.
 *
 * القائمة تُسند بعد أن يكون قد باع، فعمولاته القديمة محسوبةٌ على سعر الجملة
 * العام لا على ما يشتري به فعلًا. ومن يشتري بـ٦٥ لا يُحسب ربحه كأنه اشترى
 * بـ٨٠ — وهو سببُ وجود القائمة أصلًا.
 *
 * والحصر بمسوّقٍ واحد ليس تفصيلًا: القائمة شخصيّة، والطلب الواحد قد يحمل
 * عمولةً لغيره لا تخضع لها.
 */
class RepriceEarnerTest extends TestCase
{
    use RefreshDatabase;

    private User $earner;

    private PriceList $list;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->list = PriceList::create(['name' => 'قائمة أسعار سائد', 'is_active' => true]);

        $this->earner = User::factory()->create([
            'name' => 'سائد',
            'branch_id' => Branch::default()->id,
            'price_list_id' => $this->list->id,
        ]);
        $this->earner->assignRole('affiliate');

        // بيع 100 · جملة 80 · قائمته 65 ⇒ الهامش الصحيح 35 لا 20.
        $product = Product::factory()->create(['retail_price' => 100, 'wholesale_price' => 80]);
        $this->variant = $product->defaultVariant;
        $this->variant->update(['retail_price' => 100, 'wholesale_price' => 80, 'average_cost' => 40]);

        PriceListItem::create([
            'price_list_id' => $this->list->id,
            'variant_id' => $this->variant->id,
            'price' => 65,
        ]);
    }

    private function accrual(?User $earner = null, ?ProductVariant $variant = null, float $amount = 20): CommissionEntry
    {
        $variant ??= $this->variant;
        $earner ??= $this->earner;

        $order = Order::factory()->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => Warehouse::where('code', 'WH-MAIN')->firstOrFail()->id,
            'affiliate_id' => $earner->id,
        ]);

        $item = OrderItem::create([
            'order_id' => $order->id, 'variant_id' => $variant->id,
            'qty' => 1, 'unit_price' => 100, 'discount' => 0, 'line_total' => 100,
            'wholesale_cost_snapshot' => 40, 'wholesale_price_snapshot' => 80,
        ]);

        return CommissionEntry::create([
            'earner_type' => 'affiliate', 'earner_id' => $earner->id,
            'order_id' => $order->id, 'order_item_id' => $item->id, 'variant_id' => $variant->id,
            'entry_type' => 'accrual', 'basis' => $amount, 'rate' => 1.0, 'amount' => $amount,
            'wholesale_cost_snapshot' => 40,
            'rule_snapshot' => ['method' => 'margin', 'rate' => 1.0, 'default' => true],
            'state' => 'pending',
        ]);
    }

    private function service(): CommissionService
    {
        return app(CommissionService::class);
    }

    /** الربح يُعاد احتسابه على سعر قائمته: 100 − 65 = 35. */
    public function test_the_profit_is_recomputed_against_his_list_price(): void
    {
        $entry = $this->accrual();

        $this->service()->repriceForEarner($this->earner);

        $this->assertEqualsWithDelta(35, (float) $entry->fresh()->amount, 0.01);
        $this->assertEqualsWithDelta(35, (float) $entry->fresh()->basis, 0.01);
    }

    /** واللقطة تصير سعر قائمته، فلا تُنتج إعادةُ الاحتساب رقمًا آخر. */
    public function test_the_item_snapshot_becomes_his_list_price(): void
    {
        $entry = $this->accrual();

        $this->service()->repriceForEarner($this->earner);

        $this->assertEqualsWithDelta(65, (float) $entry->orderItem->fresh()->wholesale_price_snapshot, 0.01);
    }

    /** والعرض وحده لا يكتب شيئًا. */
    public function test_a_dry_run_changes_nothing(): void
    {
        $entry = $this->accrual();

        $changes = $this->service()->repriceForEarner($this->earner, null, apply: false);

        $this->assertCount(1, $changes);
        $this->assertEqualsWithDelta(20, (float) $entry->fresh()->amount, 0.01);
    }

    /**
     * ولا يُمسّ مسوّقٌ آخر على الطلب نفسه.
     *
     * القائمة شخصيّة — ومن لا قائمة له يبقى على سعر الجملة العام.
     */
    public function test_another_marketer_is_left_untouched(): void
    {
        $other = User::factory()->create(['branch_id' => Branch::default()->id]);
        $other->assignRole('affiliate');

        $mine = $this->accrual();
        $theirs = $this->accrual($other);

        $this->service()->repriceForEarner($this->earner);

        $this->assertEqualsWithDelta(35, (float) $mine->fresh()->amount, 0.01);
        $this->assertEqualsWithDelta(20, (float) $theirs->fresh()->amount, 0.01);
    }

    /**
     * والصنف خارج قائمته يعود إلى سعر جملته.
     *
     * كما يفعل حسم السعر في الطلب تمامًا، فلا يختلف الكشف عن المصدر.
     */
    public function test_an_item_outside_the_list_falls_back_to_wholesale(): void
    {
        $other = Product::factory()->create(['retail_price' => 100, 'wholesale_price' => 90]);
        $other->defaultVariant->update(['retail_price' => 100, 'wholesale_price' => 90]);

        $entry = $this->accrual(variant: $other->defaultVariant);

        $this->service()->repriceForEarner($this->earner);

        // 100 − 90 = 10، لا سعر قائمته (65) ولا التكلفة.
        $this->assertEqualsWithDelta(10, (float) $entry->fresh()->amount, 0.01);
    }

    /** والمدفوعة لا تُمسّ. */
    public function test_a_paid_entry_is_left_alone(): void
    {
        $entry = $this->accrual();
        $entry->forceFill(['state' => 'paid'])->save();

        $changes = $this->service()->repriceForEarner($this->earner);

        $this->assertSame('paid', $changes[0]['skipped']);
        $this->assertEqualsWithDelta(20, (float) $entry->fresh()->amount, 0.01);
    }

    /** والقائمة المعطَّلة تعني ألّا شيء يُعاد احتسابه. */
    public function test_a_disabled_list_reprices_nothing(): void
    {
        $entry = $this->accrual();
        $this->list->update(['is_active' => false]);

        $this->assertSame([], $this->service()->repriceForEarner($this->earner));
        $this->assertEqualsWithDelta(20, (float) $entry->fresh()->amount, 0.01);
    }

    /** والأثر يُدوَّن. */
    public function test_the_change_is_recorded(): void
    {
        $entry = $this->accrual();

        $this->service()->repriceForEarner($this->earner);

        $this->assertTrue(
            CommissionTransition::where('commission_entry_id', $entry->id)
                ->where('reference', 'wholesale_snapshot_correction')->exists()
        );
    }

    /** وتشغيلُه مرّتين لا يضاعف شيئًا. */
    public function test_running_it_twice_is_safe(): void
    {
        $entry = $this->accrual();

        $this->service()->repriceForEarner($this->earner);
        $this->service()->repriceForEarner($this->earner);

        $this->assertEqualsWithDelta(35, (float) $entry->fresh()->amount, 0.01);
        $this->assertSame(0, CommissionEntry::where('entry_type', 'adjustment')->count());
    }

    // ────────── الأمر ──────────

    /** الأمر بلا `--apply` يعرض ولا يكتب. */
    public function test_the_command_reports_without_writing(): void
    {
        $entry = $this->accrual();

        $this->artisan('commissions:reprice-earner', ['user' => $this->earner->email])
            ->expectsOutputToContain('عرضٌ فقط')
            ->assertSuccessful();

        $this->assertEqualsWithDelta(20, (float) $entry->fresh()->amount, 0.01);
    }

    /** ومع `--apply` ينفّذ. */
    public function test_the_command_applies_when_told_to(): void
    {
        $entry = $this->accrual();

        $this->artisan('commissions:reprice-earner', ['user' => $this->earner->email, '--apply' => true])
            ->assertSuccessful();

        $this->assertEqualsWithDelta(35, (float) $entry->fresh()->amount, 0.01);
    }

    /** ويرفض مستخدمًا لا وجود له. */
    public function test_the_command_refuses_an_unknown_user(): void
    {
        $this->artisan('commissions:reprice-earner', ['user' => 'nobody@example.test'])
            ->assertFailed();
    }

    /** ويرفض مسوّقًا بلا قائمة — بدل أن يصمت. */
    public function test_the_command_refuses_a_marketer_with_no_list(): void
    {
        $bare = User::factory()->create(['branch_id' => Branch::default()->id]);
        $bare->assignRole('affiliate');

        $this->artisan('commissions:reprice-earner', ['user' => $bare->email])
            ->expectsOutputToContain('لا قائمة أسعارٍ مفعّلة')
            ->assertFailed();
    }
}
