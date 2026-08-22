<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\PriceList;
use App\Modules\Catalog\Models\PriceListItem;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «الأصناف والأسعار» — سعر شراء صاحب القائمة لا سعر الجملة العام.
 *
 * الشاشة واحدة لكل المسوّقين، ومن أُسندت له قائمة تجّار يشتري بسعرٍ آخر. فلو
 * بقي العمود على سعر الجملة لقرأ رقمًا لا يشتري به: يحسب ربحه خطأً، ويسعّر
 * للزبون على أساسٍ فاسد.
 *
 * والاتجاه الآخر يُفحص معه: من لا قائمة له يجب أن يبقى على سعر الجملة **تمامًا
 * كما كان** — إدخال طبقة سعرٍ على نظامٍ يبيع فعلًا يجب ألّا يحرّك سعر أحد.
 */
class AffiliateDealerPriceVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function affiliate(?PriceList $list = null): User
    {
        $user = User::factory()->create([
            'branch_id' => Branch::default()->id,
            'price_list_id' => $list?->id,
        ]);
        $user->assignRole('affiliate');

        return $user;
    }

    private function list(string $name, ?int $parentId = null, bool $active = true): PriceList
    {
        return PriceList::create([
            'name' => $name,
            'parent_id' => $parentId,
            'is_active' => $active,
        ]);
    }

    private function priced(string $name, float $retail, float $wholesale): Product
    {
        $product = Product::factory()->create(['name' => $name]);
        $product->defaultVariant->update([
            'retail_price' => $retail,
            'wholesale_price' => $wholesale,
            'cost_price' => 30,
            'average_cost' => 30,
        ]);

        return $product;
    }

    private function price(PriceList $list, ProductVariant $variant, float $price): PriceListItem
    {
        return PriceListItem::create([
            'price_list_id' => $list->id,
            'variant_id' => $variant->id,
            'price' => $price,
        ]);
    }

    /** صاحب القائمة يرى سعره هو، وعنوانَ العمود «سعر شرائك». */
    public function test_a_marketer_with_a_list_sees_his_own_buying_price(): void
    {
        $list = $this->list('تجّار');
        $product = $this->priced('مكنسة', retail: 120, wholesale: 80);
        $this->price($list, $product->defaultVariant, 65);

        $this->actingAs($this->affiliate($list))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('سعر شرائك', false)
            ->assertDontSee('سعر الجملة', false)
            ->assertSee('65.00')
            ->assertDontSee('80.00');   // سعر الجملة العام لا يشتري به
    }

    /** ومن لا قائمة له يبقى على سعر الجملة كما كان. */
    public function test_a_marketer_without_a_list_still_sees_the_wholesale_price(): void
    {
        $this->priced('مكنسة', retail: 120, wholesale: 80);

        $this->actingAs($this->affiliate())
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('سعر الجملة', false)
            ->assertSee('80.00');
    }

    /**
     * والصنف غير المسعَّر في القائمة يعود إلى سعر جملته.
     *
     * القائمة الخاصّة تحمل الأصناف المختلفة وحدها؛ فلو أُخفي ما عداها لرأى
     * التاجر كتالوجًا ناقصًا، ولو عُرض بصفرٍ لظنّه مجّانًا.
     */
    public function test_an_item_missing_from_the_list_falls_back_to_wholesale(): void
    {
        $list = $this->list('تجّار');
        $inList = $this->priced('مكنسة', retail: 120, wholesale: 80);
        $this->price($list, $inList->defaultVariant, 65);

        $this->priced('مروحة', retail: 200, wholesale: 150);

        $this->actingAs($this->affiliate($list))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('65.00')
            ->assertSee('150.00');   // خارج القائمة ⇒ سعر جملته
    }

    /**
     * والوراثة تصل إلى الشاشة: قائمةٌ ابنة بسطرٍ واحد وأبٌ فيه الباقي.
     *
     * هذا هو الاستعمال المقصود — قائمة أساس للتجّار، وقائمة خاصّة لتاجرٍ فيها
     * الأصناف المختلفة وحدها.
     */
    public function test_the_child_list_inherits_the_parent_prices_on_screen(): void
    {
        $base = $this->list('تجّار');
        $special = $this->list('تاجر خاصّ', parentId: $base->id);

        $shared = $this->priced('مكنسة', retail: 120, wholesale: 80);
        $this->price($base, $shared->defaultVariant, 70);

        $discounted = $this->priced('مروحة', retail: 200, wholesale: 150);
        $this->price($base, $discounted->defaultVariant, 140);
        $this->price($special, $discounted->defaultVariant, 120);   // الأقرب يفوز

        $this->actingAs($this->affiliate($special))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('70.00')     // موروثٌ من الأب
            ->assertSee('120.00')    // مخصَّصٌ في الابنة
            ->assertDontSee('140.00');
    }

    /**
     * والقائمة المعطَّلة كأنها غير موجودة.
     *
     * تعليقُ تعاملٍ خاصّ يجب أن يكون بضغطةٍ واحدة، لا بفكّ القائمة عن كل
     * مستخدميها واحدًا واحدًا.
     */
    public function test_a_disabled_list_returns_the_marketer_to_wholesale(): void
    {
        $list = $this->list('تجّار', active: false);
        $product = $this->priced('مكنسة', retail: 120, wholesale: 80);
        $this->price($list, $product->defaultVariant, 65);

        $this->actingAs($this->affiliate($list))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('سعر الجملة', false)
            ->assertSee('80.00')
            ->assertDontSee('65.00');
    }

    /** والتكلفة تبقى محجوبة عن صاحب القائمة كما عن غيره. */
    public function test_the_cost_stays_hidden_from_a_list_holder(): void
    {
        $list = $this->list('تجّار');
        $product = $this->priced('مكنسة', retail: 120, wholesale: 80);
        $product->defaultVariant->update(['cost_price' => 47, 'average_cost' => 47]);
        $this->price($list, $product->defaultVariant, 65);

        $this->actingAs($this->affiliate($list))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertDontSee('47.00');
    }
}
