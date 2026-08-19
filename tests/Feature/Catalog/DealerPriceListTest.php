<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\PriceListService;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * قوائم أسعار التجّار.
 *
 * الغاية طبقةُ سعرٍ تُسنَد إلى أشخاص بعينهم: من له قائمة يشتري بأسعارها ويربح
 * الفرق بينها وبين سعر بيعه — كما يربح المسوّق فرقَ الجملة والبيع.
 *
 * وأثقل حارسٍ هنا هو **ألّا يتغيّر شيء لمن لا قائمة له**: إدخال طبقة سعرٍ على
 * نظامٍ يبيع فعلًا لا يجوز أن يحرّك سعر أحدٍ بالصدفة.
 */
class DealerPriceListTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private PriceListService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
        $this->service = app(PriceListService::class);
    }

    private function variant(float $retail = 100, float $wholesale = 70): ProductVariant
    {
        $product = Product::factory()->create();
        $product->defaultVariant->update([
            'retail_price' => $retail,
            'wholesale_price' => $wholesale,
            'average_cost' => 40,
        ]);

        return $product->defaultVariant->fresh();
    }

    private function dealer(?PriceList $list = null): User
    {
        $user = User::factory()->create([
            'branch_id' => Branch::default()->id,
            'price_list_id' => $list?->id,
        ]);
        $user->assignRole('affiliate');

        return $user;
    }

    private function priced(PriceList $list, ProductVariant $variant, float $price): PriceList
    {
        PriceListItem::create([
            'price_list_id' => $list->id,
            'variant_id' => $variant->id,
            'price' => $price,
        ]);

        return $list;
    }

    private function sell(User $dealer, ProductVariant $variant, float $unitPrice): Order
    {
        $this->actingAs($dealer);

        return app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => 'زبون',
            'customer_phone' => '0599000000',
            'affiliate_id' => $dealer->id,
        ], [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => $unitPrice]], 2026);
    }

    // ────────── حسم السعر ──────────

    /** من لا قائمة له يبقى على سعر الجملة — الحارس الأثقل. */
    public function test_a_user_without_a_list_keeps_the_wholesale_price(): void
    {
        $variant = $this->variant(wholesale: 70);

        $this->assertEqualsWithDelta(70.0, $this->service->buyPrice($this->dealer(), $variant), 0.01);
    }

    /** وصاحب القائمة يشتري بسعرها. */
    public function test_a_list_price_replaces_the_wholesale_price(): void
    {
        $variant = $this->variant(wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);

        $this->assertEqualsWithDelta(55.0, $this->service->buyPrice($this->dealer($list), $variant), 0.01);
    }

    /** وصنفٌ غير مسعَّر في القائمة يعود إلى سعر الجملة. */
    public function test_an_unpriced_variant_falls_back_to_wholesale(): void
    {
        $variant = $this->variant(wholesale: 70);
        $list = PriceList::create(['name' => 'أسعار تجّار']);

        $this->assertEqualsWithDelta(70.0, $this->service->buyPrice($this->dealer($list), $variant), 0.01);
    }

    /**
     * والقائمة الخاصّة ترث من الأب وتتقدّم عليه.
     *
     * وهذا ما يجعل تخصيص تاجرٍ بعينه ممكنًا بلا تكرار مئة صنف من أجل خمسة.
     */
    public function test_a_child_list_overrides_its_parent_and_inherits_the_rest(): void
    {
        $overridden = $this->variant(wholesale: 70);
        $inherited = $this->variant(wholesale: 80);

        $base = PriceList::create(['name' => 'أسعار تجّار']);
        $this->priced($base, $overridden, 55);
        $this->priced($base, $inherited, 60);

        $private = PriceList::create(['name' => 'تاجر مميّز', 'parent_id' => $base->id]);
        $this->priced($private, $overridden, 50);

        $dealer = $this->dealer($private);

        $this->assertEqualsWithDelta(50.0, $this->service->buyPrice($dealer, $overridden), 0.01);
        $this->assertEqualsWithDelta(60.0, $this->service->buyPrice($dealer, $inherited), 0.01);
    }

    /** والقائمة المعطَّلة كأنها غير موجودة. */
    public function test_an_inactive_list_is_ignored(): void
    {
        $variant = $this->variant(wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'موقوفة', 'is_active' => false]), $variant, 55);

        $this->assertEqualsWithDelta(70.0, $this->service->buyPrice($this->dealer($list), $variant), 0.01);
    }

    // ────────── الطلب والربح ──────────

    /**
     * سعر القائمة يُجمَّد على بند الطلب في اللقطة نفسها.
     *
     * وهي الحلقة التي يعلّق بها كل ما بعدها: العمولة وتقارير ربح المسوّق تقرأ
     * هذه اللقطة، فيصير ربح التاجر = سعر بيعه − سعر قائمته بلا سطرٍ يتغيّر
     * فيها.
     */
    public function test_the_order_item_freezes_the_dealer_price(): void
    {
        $variant = $this->variant(retail: 100, wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);

        $order = $this->sell($this->dealer($list), $variant, 100);

        $this->assertEqualsWithDelta(55.0, (float) $order->items->first()->wholesale_price_snapshot, 0.01);
    }

    /** وتغيير القائمة بعد البيع لا يحرّك ربح طلبٍ مضى. */
    public function test_changing_the_list_later_never_moves_a_past_order(): void
    {
        $variant = $this->variant(retail: 100, wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);

        $order = $this->sell($this->dealer($list), $variant, 100);
        PriceListItem::where('price_list_id', $list->id)->update(['price' => 30]);

        $this->assertEqualsWithDelta(55.0, (float) $order->fresh('items')->items->first()->wholesale_price_snapshot, 0.01);
    }

    /** وطلب من لا قائمة له يبقى على سعر الجملة كما كان. */
    public function test_an_order_without_a_list_still_snapshots_wholesale(): void
    {
        $variant = $this->variant(retail: 100, wholesale: 70);

        $order = $this->sell($this->dealer(), $variant, 100);

        $this->assertEqualsWithDelta(70.0, (float) $order->items->first()->wholesale_price_snapshot, 0.01);
    }

    // ────────── حدّ السعر ──────────

    /**
     * والتاجر يبيع بأقلّ من سعر الجملة ما دام فوق سعر قائمته.
     *
     * ولولا ذلك لَمُنع من البيع بما اشترى به — وهو سبب وجود القائمة أصلًا.
     */
    public function test_a_dealer_may_sell_below_the_general_wholesale_price(): void
    {
        $variant = $this->variant(retail: 100, wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);

        $order = $this->sell($this->dealer($list), $variant, 60);

        $this->assertEqualsWithDelta(60.0, (float) $order->items->first()->unit_price, 0.01);
    }

    /** ولا يبيع بأقلّ من سعر قائمته. */
    public function test_a_dealer_may_not_sell_below_their_own_list_price(): void
    {
        $variant = $this->variant(retail: 100, wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);

        $this->expectException(ValidationException::class);

        $this->sell($this->dealer($list), $variant, 50);
    }

    // ────────── الحلقات والصلاحيات ──────────

    /** ولا تكون القائمة أبًا لنفسها — حلقةٌ تُدخل حسم السعر في دورانٍ لا ينتهي. */
    public function test_a_list_cannot_inherit_from_itself(): void
    {
        $list = PriceList::create(['name' => 'قائمة']);

        $this->expectException(ValidationException::class);

        $this->service->assertNoCycle($list, $list->id);
    }

    /** ولا وراثة متبادلة. */
    public function test_two_lists_cannot_inherit_from_each_other(): void
    {
        $parent = PriceList::create(['name' => 'أب']);
        $child = PriceList::create(['name' => 'ابن', 'parent_id' => $parent->id]);

        $this->expectException(ValidationException::class);

        $this->service->assertNoCycle($parent, $child->id);
    }

    /** الشاشة للإدارة وحدها — القائمة قرار تسعيرٍ لا إدخال بيانات. */
    public function test_the_screen_is_admin_only(): void
    {
        $admin = User::where('email', 'admin@tawfeer.online')->firstOrFail();
        $this->actingAs($admin)->get(route('admin.price_lists.index'))->assertOk();

        foreach (['sales', 'affiliate', 'warehouse'] as $role) {
            $user = User::factory()->create(['branch_id' => Branch::default()->id]);
            $user->assignRole($role);

            $this->actingAs($user)->get(route('admin.price_lists.index'))->assertForbidden();
        }
    }

    /** وحذف القائمة يعيد أصحابها إلى سعر الجملة لا يمنع الحذف. */
    public function test_deleting_a_list_returns_its_users_to_wholesale(): void
    {
        $variant = $this->variant(wholesale: 70);
        $list = $this->priced(PriceList::create(['name' => 'أسعار تجّار']), $variant, 55);
        $dealer = $this->dealer($list);

        $this->actingAs(User::where('email', 'admin@tawfeer.online')->firstOrFail())
            ->delete(route('admin.price_lists.destroy', $list))
            ->assertRedirect();

        $this->assertNull($dealer->fresh()->price_list_id);
        $this->assertEqualsWithDelta(70.0, $this->service->buyPrice($dealer->fresh(), $variant), 0.01);
    }
}
