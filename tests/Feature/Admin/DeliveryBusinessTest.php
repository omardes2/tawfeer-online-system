<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Foundation\Models\DeliveryBusiness;
use App\Modules\Shipping\Services\DeliveryBusinessSyncService;
use App\Support\Integrations\Shipping\NullDeliveryProvider;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** مزوّد توصيل مزيّف يعيد حسابات بزنس ثابتة للاختبار. */
class FakeBusinessDeliveryProvider extends NullDeliveryProvider
{
    /** @var array<int, array<string, mixed>> */
    public static array $rows = [];

    public function pullBusinesses(): iterable
    {
        return static::$rows;
    }
}

class DeliveryBusinessTest extends TestCase
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

    private function useFakeProvider(array $rows): void
    {
        FakeBusinessDeliveryProvider::$rows = $rows;
        config([
            'shipping.provider' => 'fake',
            'shipping.drivers.fake.delivery' => FakeBusinessDeliveryProvider::class,
        ]);
    }

    public function test_sync_service_upserts_then_deactivates_missing(): void
    {
        $this->useFakeProvider([
            ['external_id' => '100', 'name' => 'بزنس أ', 'address_external_id' => '10', 'phone' => null, 'raw' => []],
            ['external_id' => '200', 'name' => 'بزنس ب', 'address_external_id' => null, 'phone' => null, 'raw' => []],
        ]);

        $result = app(DeliveryBusinessSyncService::class)->sync();
        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseHas('delivery_businesses', ['provider' => 'fake', 'external_id' => '100', 'name' => 'بزنس أ', 'is_active' => true]);

        // إعادة المزامنة بقائمة أقصر → البزنس المفقود يُعطَّل (لا يُحذف).
        $this->useFakeProvider([['external_id' => '100', 'name' => 'بزنس أ', 'raw' => []]]);
        app(DeliveryBusinessSyncService::class)->sync();

        $this->assertDatabaseHas('delivery_businesses', ['external_id' => '200', 'is_active' => false]);
        $this->assertDatabaseHas('delivery_businesses', ['external_id' => '100', 'is_active' => true]);
    }

    public function test_sync_endpoint_imports_businesses(): void
    {
        $this->useFakeProvider([['external_id' => '55', 'name' => 'بزنس رئيسي', 'raw' => []]]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.delivery_businesses.sync'))
            ->assertRedirect();

        $this->assertDatabaseHas('delivery_businesses', ['external_id' => '55', 'name' => 'بزنس رئيسي']);
    }

    public function test_sync_endpoint_reports_reason_when_not_linked(): void
    {
        // لا مزوّد ولا بيانات Opost → رسالة خطأ إرشادية بدل نجاح صامت.
        config(['shipping.provider' => 'null', 'services.opost.username' => null, 'services.opost.token' => null, 'services.opost.client_id' => null]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.delivery_businesses.sync'))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('delivery_businesses', 0);
    }

    public function test_manual_add_edit_and_delete_business(): void
    {
        // إضافة يدوية
        $this->actingAs($this->admin())->post(route('admin.users.delivery_businesses.store'), [
            'external_id' => '13359', 'name' => 'Tawfeer_web', 'address_external_id' => '42', 'phone' => '0599880023', 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $biz = DeliveryBusiness::where('external_id', '13359')->firstOrFail();
        $this->assertSame('Tawfeer_web', $biz->name);

        // تعديل + تعطيل
        $this->actingAs($this->admin())->put(route('admin.users.delivery_businesses.update', $biz), [
            'external_id' => '13359', 'name' => 'توفير ويب', 'is_active' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $biz->refresh();
        $this->assertSame('توفير ويب', $biz->name);
        $this->assertFalse($biz->is_active);

        // ربط مستخدم ثم حذف الحساب → يُلغى الربط (FK nullOnDelete)
        $user = User::factory()->create(['delivery_business_id' => $biz->id]);
        $this->actingAs($this->admin())->delete(route('admin.users.delivery_businesses.destroy', $biz))->assertRedirect();
        $this->assertDatabaseMissing('delivery_businesses', ['id' => $biz->id]);
        $this->assertNull($user->fresh()->delivery_business_id);
    }

    public function test_manual_add_rejects_duplicate_external_id(): void
    {
        DeliveryBusiness::create(['provider' => 'opost', 'external_id' => '13359', 'name' => 'موجود']);

        $this->actingAs($this->admin())->post(route('admin.users.delivery_businesses.store'), [
            'external_id' => '13359', 'name' => 'مكرر',
        ])->assertSessionHasErrors('external_id');

        $this->assertSame(1, DeliveryBusiness::where('external_id', '13359')->count());
    }

    public function test_user_form_saves_and_clears_delivery_business(): void
    {
        $biz = DeliveryBusiness::create(['provider' => 'opost', 'external_id' => '9', 'name' => 'حساب البزنس']);

        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'موظف مبيعات', 'email' => 'emp@ex.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'role' => 'sales', 'delivery_business_id' => $biz->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $user = User::where('email', 'emp@ex.com')->first();
        $this->assertSame($biz->id, $user->delivery_business_id);

        // التحديث بقيمة فارغة → يُلغى الربط.
        $this->actingAs($this->admin())->put(route('admin.users.update', $user), [
            'name' => 'موظف مبيعات', 'email' => 'emp@ex.com', 'role' => 'sales', 'delivery_business_id' => '',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->delivery_business_id);
    }
}
