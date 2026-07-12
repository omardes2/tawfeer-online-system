<?php

namespace Tests\Feature\Payment;

use App\Modules\Foundation\Models\PaymentMethod;
use App\Support\Contracts\Payment\PaymentChargeRequest;
use App\Support\Contracts\Payment\PaymentProviderInterface;
use App\Support\Integrations\Payment\NullPaymentProvider;
use App\Support\Integrations\Payment\OfflinePaymentProvider;
use App\Support\Integrations\Payment\PaymentProviderManager;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentProviderLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_manager_resolves_offline_driver_for_cod(): void
    {
        $manager = app(PaymentProviderManager::class);
        $cod = PaymentMethod::where('code', 'cod')->firstOrFail();

        $provider = $manager->for($cod);
        $this->assertInstanceOf(OfflinePaymentProvider::class, $provider);
        $this->assertInstanceOf(PaymentProviderInterface::class, $provider);
        $this->assertEquals('offline', $provider->name());
    }

    public function test_manager_resolves_null_driver_for_unconfigured_gateway(): void
    {
        $manager = app(PaymentProviderManager::class);
        $stripe = PaymentMethod::where('code', 'stripe')->firstOrFail();

        $this->assertInstanceOf(NullPaymentProvider::class, $manager->for($stripe));
    }

    public function test_adding_a_provider_needs_only_a_method_row_and_driver_map(): void
    {
        // إضافة مزوّد جديد = صفّ طريقة يشير إلى Driver مُسجَّل — دون لمس منطق الطلب/الدفع.
        $method = PaymentMethod::create([
            'name' => 'مزوّد مستقبلي', 'code' => 'future', 'driver' => 'offline', 'type' => 'online', 'is_active' => true,
        ]);

        $provider = app(PaymentProviderManager::class)->for($method);
        $this->assertInstanceOf(OfflinePaymentProvider::class, $provider);
    }

    public function test_offline_driver_charge_is_pending_capture_is_paid(): void
    {
        $provider = new OfflinePaymentProvider;
        $this->assertEquals('pending', $provider->charge(new PaymentChargeRequest(20000, 'SAR', 'SO-1', 'cod'))->status);
        $this->assertTrue($provider->capture('ref')->isPaid());
    }
}
