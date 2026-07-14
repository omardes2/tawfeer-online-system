<?php

namespace Tests\Feature\Shipping;

use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use App\Support\Integrations\Shipping\OpostGeographySyncProvider;
use App\Support\Integrations\Shipping\OpostTokenProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * توكن Opost عبر OAuth2 (Password Grant): تسجيل دخول آلي + تخزين + تجديد عند 401.
 */
class OpostAuthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.opost.oauth_url', 'https://opost.ps/oauth/token');
        config()->set('services.opost.client_id', 'cid');
        config()->set('services.opost.client_secret', 'secret');
        config()->set('services.opost.username', 'me@example.com');
        config()->set('services.opost.password', 'pass');
        config()->set('services.opost.base_url', 'https://opost.ps/api');
    }

    public function test_token_provider_logs_in_and_caches(): void
    {
        Http::fake([
            'opost.ps/oauth/token' => Http::response(['access_token' => 'TKN-1', 'expires_in' => 3600], 200),
        ]);

        $auth = app(OpostTokenProvider::class);
        $this->assertSame('TKN-1', $auth->token());
        // نداء ثانٍ يأتي من الكاش — لا استدعاء دخول إضافي.
        $this->assertSame('TKN-1', $auth->token());

        Http::assertSentCount(1);
    }

    public function test_falls_back_to_static_token_when_oauth_not_configured(): void
    {
        config()->set('services.opost.client_id', '');
        config()->set('services.opost.username', '');
        config()->set('services.opost.token', 'STATIC-TKN');

        $this->assertSame('STATIC-TKN', app(OpostTokenProvider::class)->token());
    }

    public function test_cancel_posts_to_actions_cancel_endpoint(): void
    {
        Http::fake([
            'opost.ps/oauth/token' => Http::response(['access_token' => 'T', 'expires_in' => 3600], 200),
            'opost.ps/api/resources/shipments/actions/cancel' => Http::response(['status' => 'ok'], 200),
        ]);

        $ok = (new OpostDeliveryProvider)->cancel('12345');

        $this->assertTrue($ok);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/resources/shipments/actions/cancel')
            && $req['selected_ids'][0] === '12345');
    }

    public function test_geography_sync_reauthenticates_on_401(): void
    {
        $calls = 0;
        Http::fake([
            'opost.ps/oauth/token' => Http::response(['access_token' => 'TKN-FRESH', 'expires_in' => 3600], 200),
            'opost.ps/api/resources/cities*' => function () use (&$calls) {
                $calls++;

                // أول نداء 401 (توكن منتهٍ)، الثاني ينجح بعد التجديد.
                // شكل Opost الفعلي: قائمة عناصرها {data:[...]}.
                return $calls === 1
                    ? Http::response(['message' => 'Unauthenticated.'], 401)
                    : Http::response([['data' => [['id' => 5, 'name' => 'رام الله']]]], 200);
            },
            'opost.ps/api/resources/areas*' => Http::response(['data' => []], 200),
        ]);

        // توكن مخزّن مسبقًا «منتهٍ» لإجبار مسار 401 ثم التجديد.
        cache()->put('integrations:opost:access_token', 'STALE', 3600);

        $cities = iterator_to_array(app(OpostGeographySyncProvider::class)->pullCities());

        $this->assertCount(1, $cities);
        $this->assertSame('رام الله', $cities[0]['name']);
        $this->assertSame('5', $cities[0]['external_id']);
        $this->assertSame(2, $calls); // 401 ثم نجاح
    }
}
