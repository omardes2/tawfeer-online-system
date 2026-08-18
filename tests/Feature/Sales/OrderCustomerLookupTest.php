<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\Branch;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\Governorate;
use App\Modules\Foundation\Models\Warehouse;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\OrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * تعرّف الزبون بالهاتف لتعبئة نموذج الطلب.
 *
 * الوعد: من يُدخل طلبًا لزبونٍ سبق أن طلب يجد بياناته جاهزة. والقيد الذي يحرسه
 * الاختبار الأهمّ: **البحث مشترك** — لا يُقصَر على طلبات المستخدم الحالي، فمسوّقٌ
 * يتعرّف على زبونٍ أدخل طلبَه موظفُ مبيعاتٍ آخر. الزبون واحدٌ للشركة لا للموظف.
 */
class OrderCustomerLookupTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->warehouse = Warehouse::where('code', 'WH-MAIN')->firstOrFail();
    }

    private function city(string $name): City
    {
        $gov = Governorate::query()->firstOrCreate(['name' => 'محافظة'], ['is_active' => true]);

        return City::create(['governorate_id' => $gov->id, 'name' => $name, 'is_active' => true]);
    }

    /** طلبٌ سابق لزبونٍ برقمٍ وعنوانٍ محدّد — بأي مستخدمٍ كان. */
    private function pastOrder(string $phone, array $attrs): Order
    {
        $variant = Product::factory()->create()->defaultVariant;

        $order = app(OrderService::class)->create([
            'branch_id' => Branch::default()->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_name' => $attrs['customer_name'],
            'customer_phone' => $phone,
        ] + $attrs, [['variant_id' => $variant->id, 'qty' => 1, 'unit_price' => 100]], 2026);

        $order->update($attrs);

        return $order->refresh();
    }

    private function seller(string $role = 'sales'): User
    {
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        return $user;
    }

    private function lookup(User $as, string $phone)
    {
        return $this->actingAs($as)->getJson(route('admin.sales.orders.customer_lookup', ['phone' => $phone]));
    }

    // ────────── التعرّف ──────────

    /** الرقم المعروف يعيد بيانات آخر طلب. */
    public function test_a_known_phone_returns_the_last_order_details(): void
    {
        $city = $this->city('نابلس');
        $area = Area::create(['city_id' => $city->id, 'name' => 'رفيديا', 'is_active' => true]);

        $this->pastOrder('0599123456', [
            'customer_name' => 'عمر شاهين',
            'city_id' => $city->id,
            'area_id' => $area->id,
            'shipping_address' => 'شارع الجامعة، بناية 5',
        ]);

        $this->lookup($this->seller(), '0599123456')
            ->assertOk()
            ->assertJson([
                'found' => true,
                'name' => 'عمر شاهين',
                'city_id' => $city->id,
                'area_id' => $area->id,
                'city' => 'نابلس',
                'area' => 'رفيديا',
                'address' => 'شارع الجامعة، بناية 5',
            ]);
    }

    /** والأحدث يغلب: زبونٌ نقل عنوانه يعيد عنوانه الجديد. */
    public function test_the_most_recent_order_wins(): void
    {
        $city = $this->city('رام الله');
        $this->pastOrder('0599123456', ['customer_name' => 'عمر', 'city_id' => $city->id, 'shipping_address' => 'العنوان القديم']);
        $this->pastOrder('0599123456', ['customer_name' => 'عمر', 'city_id' => $city->id, 'shipping_address' => 'العنوان الجديد']);

        $this->lookup($this->seller(), '0599123456')
            ->assertOk()
            ->assertJson(['found' => true, 'address' => 'العنوان الجديد']);
    }

    /** والتطبيع يطابق: صيغة الرقم لا تمنع التعرّف. */
    public function test_phone_normalization_matches(): void
    {
        $this->pastOrder('0599123456', ['customer_name' => 'عمر']);

        $this->lookup($this->seller(), '970599123456')->assertOk()->assertJson(['found' => true, 'name' => 'عمر']);
    }

    /** والرقم المجهول يعيد «غير موجود» بهدوء. */
    public function test_an_unknown_phone_returns_not_found(): void
    {
        $this->lookup($this->seller(), '0599000000')->assertOk()->assertJson(['found' => false]);
    }

    /** ورقمٌ ناقص لا يُبحَث به — جزءُ رقمٍ يطابق زبونًا غير المقصود. */
    public function test_a_short_phone_is_not_searched(): void
    {
        $this->pastOrder('0599123456', ['customer_name' => 'عمر']);

        $this->lookup($this->seller(), '059')->assertOk()->assertJson(['found' => false]);
    }

    // ────────── المشاركة ──────────

    /**
     * البحث مشترك: مسوّقٌ يتعرّف على زبونٍ أدخل طلبَه موظفُ مبيعاتٍ آخر.
     *
     * لو قُصر البحث على طلبات المستخدم لضاعت الفائدة الأساسية — الزبون واحدٌ
     * للشركة، ولا يعرفه موظفٌ ويجهله زميله.
     */
    public function test_the_lookup_is_shared_across_all_staff(): void
    {
        $employee = $this->seller('sales');
        $this->actingAs($employee);
        $this->pastOrder('0599123456', ['customer_name' => 'عمر', 'assigned_to' => $employee->id]);

        // مسوّقٌ مختلفٌ تمامًا يتعرّف على الزبون نفسه.
        $affiliate = $this->seller('affiliate');

        $this->lookup($affiliate, '0599123456')->assertOk()->assertJson(['found' => true, 'name' => 'عمر']);
    }

    // ────────── الصلاحية ──────────

    /** من لا يملك صلاحية إنشاء الطلب لا يبحث. */
    public function test_it_requires_the_order_create_permission(): void
    {
        $role = Role::findOrCreate('viewer-only', 'web');
        $role->givePermissionTo('sales.orders.view');
        $user = User::factory()->create(['branch_id' => Branch::default()->id]);
        $user->assignRole($role);

        $this->lookup($user, '0599123456')->assertForbidden();
    }
}
