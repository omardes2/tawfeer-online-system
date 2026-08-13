<?php

namespace Tests\Feature\Storefront;

use App\Modules\Foundation\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * بوّابة الطلب أونلاين (`store.online_orders_enabled`).
 *
 * الاختبار الحاسم هو إغلاق **الواجهة البرمجية** لا الصفحة وحدها: صفحة الإتمام
 * تنادي `/api/v1/store/checkout` من JavaScript، فإغلاق الصفحة دون الواجهة يترك
 * الطلب ممكنًا بطلب HTTP واحد.
 */
class OnlineOrdersGateTest extends TestCase
{
    use RefreshDatabase;

    private function setGate(bool $enabled): void
    {
        Settings::set('store.online_orders_enabled', $enabled, 'store', 'boolean');
        Settings::flush();
    }

    public function test_checkout_page_is_open_by_default(): void
    {
        // بلا إعداد محفوظ: الافتراض «مفعّل» كي لا يوقف غيابُ الإعداد المبيعات.
        $this->get(route('storefront.checkout'))->assertOk();
    }

    public function test_checkout_page_is_closed_when_gate_is_off(): void
    {
        $this->setGate(false);

        $this->get(route('storefront.checkout'))
            ->assertStatus(503)
            ->assertSee(__('storefront.orders_disabled_title'));
    }

    public function test_checkout_api_is_closed_when_gate_is_off(): void
    {
        $this->setGate(false);

        $this->withHeaders(['X-Cart-Token' => (string) Str::uuid()])
            ->postJson('/api/v1/store/checkout')
            ->assertStatus(503)
            ->assertJsonPath('code', 'online_orders_disabled');
    }

    public function test_cart_api_keeps_working_when_gate_is_off(): void
    {
        $this->setGate(false);

        // السلة عمدًا خارج البوّابة: الزبون يجمع ما يريد ويُبلَّغ عند الإتمام.
        $this->withHeaders(['X-Cart-Token' => (string) Str::uuid()])
            ->getJson('/api/v1/store/cart')
            ->assertOk();
    }

    public function test_storefront_browsing_is_unaffected_when_gate_is_off(): void
    {
        $this->setGate(false);

        $this->get(route('storefront.home'))->assertOk();
        $this->get(route('storefront.shop'))->assertOk();
        $this->get(route('storefront.cart'))->assertOk();
    }

    public function test_gate_reopens_when_switched_back_on(): void
    {
        $this->setGate(false);
        $this->get(route('storefront.checkout'))->assertStatus(503);

        $this->setGate(true);
        $this->get(route('storefront.checkout'))->assertOk();
    }
}
