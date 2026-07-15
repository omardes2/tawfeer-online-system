<?php

namespace Tests\Feature\Admin;

use App\Modules\Foundation\Models\City;
use App\Modules\Sales\Models\Order;
use App\Support\SystemHealth;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryHealthTest extends TestCase
{
    use RefreshDatabase;

    private int $cityId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->cityId = (int) City::query()->value('id');
    }

    private function stuckOrder(array $attrs = []): Order
    {
        return Order::factory()->confirmed()->create(array_merge([
            'city_id' => $this->cityId,
            'channel' => 'manual',
            'confirmed_at' => now()->subMinutes(10),
            'tracking_number' => null,
        ], $attrs));
    }

    public function test_delivery_disabled_when_provider_null(): void
    {
        config()->set('shipping.provider', 'null');
        $this->stuckOrder();

        $h = SystemHealth::delivery();
        $this->assertFalse($h['enabled']);
        $this->assertFalse($h['ok']);
        $this->assertSame(0, $h['stuck']); // لا نحسب العالقين حين لا مزوّد.
    }

    public function test_delivery_ok_when_provider_set_and_no_stuck_orders(): void
    {
        config()->set('shipping.provider', 'opost');

        $h = SystemHealth::delivery();
        $this->assertTrue($h['enabled']);
        $this->assertSame(0, $h['stuck']);
        $this->assertTrue($h['ok']);
    }

    public function test_stuck_delivery_order_without_tracking_marks_not_ok(): void
    {
        config()->set('shipping.provider', 'opost');
        $this->stuckOrder();

        $h = SystemHealth::delivery();
        $this->assertGreaterThanOrEqual(1, $h['stuck']);
        $this->assertFalse($h['ok']);
    }

    public function test_recently_confirmed_order_is_within_grace_and_not_stuck(): void
    {
        config()->set('shipping.provider', 'opost');
        $this->stuckOrder(['confirmed_at' => now()]); // ضمن مهلة السماح.

        $h = SystemHealth::delivery();
        $this->assertSame(0, $h['stuck']);
        $this->assertTrue($h['ok']);
    }

    public function test_order_with_tracking_or_pos_channel_is_not_stuck(): void
    {
        config()->set('shipping.provider', 'opost');
        $this->stuckOrder(['tracking_number' => 'TRK-1']);     // مُرسَل مسبقًا.
        $this->stuckOrder(['channel' => 'pos', 'city_id' => null]); // مبيعة مباشرة.

        $h = SystemHealth::delivery();
        $this->assertSame(0, $h['stuck']);
        $this->assertTrue($h['ok']);
    }
}
