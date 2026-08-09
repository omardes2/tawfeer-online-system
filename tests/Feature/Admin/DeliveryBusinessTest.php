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
