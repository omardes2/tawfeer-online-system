<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingAdminWebTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@tawfeer.online')->first();
    }

    private function withRole(string $role): User
    {
        $u = User::factory()->create(['branch_id' => Branch::default()->id]);
        $u->assignRole($role);

        return $u;
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get('/admin/shipping/shipments')->assertRedirect('/login');
    }

    public function test_shipments_index_renders_rtl(): void
    {
        $response = $this->actingAs($this->admin())->get('/admin/shipping/shipments');
        $response->assertOk();
        $response->assertSee('dir="rtl"', false);
        $response->assertSee('الشحنات');
    }

    public function test_geography_index_renders(): void
    {
        $this->actingAs($this->admin())->get('/admin/shipping/geography')
            ->assertOk()->assertSee('المحافظات والمدن');
    }

    public function test_create_lists_shippable_orders(): void
    {
        $this->actingAs($this->admin())->get('/admin/shipping/shipments/create')->assertOk();
    }

    public function test_accountant_cannot_open_create(): void
    {
        // المحاسب يملك view فقط، لا create.
        $this->actingAs($this->withRole('accountant'))->get('/admin/shipping/shipments/create')->assertForbidden();
    }

    public function test_affiliate_cannot_view_shipments(): void
    {
        $this->actingAs($this->withRole('affiliate'))->get('/admin/shipping/shipments')->assertForbidden();
    }

    public function test_areas_page_renders_and_supports_crud(): void
    {
        $city = City::orderBy('id')->firstOrFail();

        $this->actingAs($this->admin())->get(route('admin.shipping.areas.index', ['city' => $city->id]))
            ->assertOk()->assertSee('مناطق الشحن');

        // إضافة منطقة
        $this->actingAs($this->admin())->post(route('admin.shipping.areas.store'), [
            'city_id' => $city->id, 'name' => 'منطقة اختبار',
        ])->assertRedirect();
        $area = Area::where('name', 'منطقة اختبار')->firstOrFail();
        $this->assertEquals($city->id, $area->city_id);

        // تعديل + تفعيل
        $this->actingAs($this->admin())->put(route('admin.shipping.areas.update'), [
            'areas' => [$area->id => ['name' => 'منطقة معدّلة', 'is_active' => '1']],
        ])->assertRedirect();
        $this->assertEquals('منطقة معدّلة', $area->fresh()->name);

        // حذف
        $this->actingAs($this->admin())->delete(route('admin.shipping.areas.destroy', $area))->assertRedirect();
        $this->assertNull(Area::find($area->id));
    }

    public function test_area_manage_requires_permission(): void
    {
        $city = City::orderBy('id')->firstOrFail();
        $this->actingAs($this->withRole('affiliate'))->post(route('admin.shipping.areas.store'), [
            'city_id' => $city->id, 'name' => 'x',
        ])->assertForbidden();
    }

    public function test_admin_can_delete_city_with_areas(): void
    {
        $city = City::create(['governorate_id' => Governorate::orderBy('id')->value('id'), 'name' => 'مدينة للحذف']);
        Area::create(['city_id' => $city->id, 'name' => 'منطقة تابعة', 'is_active' => true]);

        $this->actingAs($this->admin())->delete(route('admin.shipping.cities.destroy', $city))->assertRedirect();

        $this->assertNull(City::find($city->id));
        $this->assertEquals(0, Area::where('city_id', $city->id)->count());
    }
}
