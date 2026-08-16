<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Branch;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منع صنفٍ عن المسوّقين — مفتاحٌ مستقلّ عن الظهور على الموقع.
 *
 * الصنف الممنوع يختفي عن المسوّق في شاشة إنشاء الطلب وفي «الأصناف والأسعار»،
 * ويبقى كما هو للزبون ولموظفي المبيعات: خلط المفتاحين كان يعني أن منع المسوّق
 * يُخفي الصنف عن المتجر أيضًا.
 */
class AffiliateProductAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Product $blocked;

    private Product $allowed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // `is_active` مطفأ في المصنع، وشاشة الطلب لا تعرض إلا الأصناف الفاعلة.
        $this->blocked = Product::factory()->create(['name' => 'صنف ممنوع على المسوّق', 'is_active' => true]);
        $this->blocked->update(['available_to_affiliates' => false]);
        $this->allowed = Product::factory()->create(['name' => 'صنف متاح للجميع', 'is_active' => true]);
    }

    private function withRole(string $role): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    public function test_new_products_are_available_by_default(): void
    {
        $this->assertTrue($this->allowed->fresh()->available_to_affiliates);
    }

    public function test_the_toggle_flips_the_flag(): void
    {
        $this->actingAs($this->withRole('admin'))
            ->post(route('admin.products.toggle-affiliate', $this->blocked))
            ->assertRedirect();

        $this->assertTrue($this->blocked->fresh()->available_to_affiliates);

        $this->post(route('admin.products.toggle-affiliate', $this->blocked));
        $this->assertFalse($this->blocked->fresh()->available_to_affiliates);
    }

    /** المنع لا يمسّ ظهور الصنف على الموقع — مفتاحان لا واحد. */
    public function test_blocking_does_not_touch_site_visibility(): void
    {
        $before = $this->allowed->visibility;

        $this->actingAs($this->withRole('admin'))
            ->post(route('admin.products.toggle-affiliate', $this->allowed));

        $this->assertSame($before, $this->allowed->fresh()->visibility);
    }

    public function test_the_price_list_hides_it_from_the_affiliate(): void
    {
        $this->actingAs($this->withRole('affiliate'))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('صنف متاح للجميع', false)
            ->assertDontSee('صنف ممنوع على المسوّق', false);
    }

    /** والمدير يراه موسومًا ليعرف ما يُخفيه عنهم. */
    public function test_the_manager_still_sees_it_flagged(): void
    {
        $this->actingAs($this->withRole('admin'))
            ->get(route('admin.price_list'))
            ->assertOk()
            ->assertSee('صنف ممنوع على المسوّق', false)
            ->assertSee(__('مخفي عن المسوّقين'), false);
    }

    public function test_the_order_screen_hides_it_from_the_affiliate_only(): void
    {
        $names = fn (string $role) => collect(
            $this->actingAs($this->withRole($role))
                ->get(route('admin.sales.orders.create'))->assertOk()->viewData('products')
        )->pluck('name');

        $affiliate = $names('affiliate');
        $this->assertContains('صنف متاح للجميع', $affiliate->all());
        $this->assertNotContains('صنف ممنوع على المسوّق', $affiliate->all());

        // موظف المبيعات يبيعه كالمعتاد.
        $this->assertContains('صنف ممنوع على المسوّق', $names('sales')->all());
    }
}
