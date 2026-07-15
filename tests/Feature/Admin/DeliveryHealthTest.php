<?php

namespace Tests\Feature\Admin;

use App\Support\SystemHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_disabled_when_provider_null(): void
    {
        config()->set('shipping.provider', 'null');

        $h = SystemHealth::delivery();
        $this->assertFalse($h['enabled']);
        $this->assertFalse($h['ok']);
    }

    public function test_delivery_ok_when_provider_set_and_queue_clean(): void
    {
        config()->set('shipping.provider', 'opost');

        $h = SystemHealth::delivery();
        $this->assertTrue($h['enabled']);
        $this->assertTrue($h['queue_healthy']);
        $this->assertSame(0, $h['failed']);
        $this->assertTrue($h['ok']);
    }

    public function test_stale_pending_job_marks_queue_unhealthy(): void
    {
        config()->set('shipping.provider', 'opost');
        config()->set('queue.default', 'database');

        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time() - 600, 'created_at' => time() - 600,
        ]);

        $h = SystemHealth::delivery();
        $this->assertGreaterThanOrEqual(1, $h['pending']);
        $this->assertFalse($h['queue_healthy']);
        $this->assertFalse($h['ok']);
    }
}
