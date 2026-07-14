<?php

namespace Tests\Feature\Shipping;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Shipping\Services\DeliveryRateSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * مزامنة جغرافيا Opost من طرف لطرف — بشكل الرد الفعلي [ { data: [...] } ].
 */
class OpostGeoSyncTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_sync_imports_cities_and_areas_from_wrapped_response(): void
    {
        Http::fake([
            'opost.ps/oauth/token' => Http::response(['access_token' => 'TKN', 'expires_in' => 3600], 200),
            // شكل Opost: قائمة عنصرها الأول يحمل data.
            'opost.ps/api/resources/cities*' => Http::response([[
                'data' => [
                    ['id' => 11, 'name' => 'رام الله'],
                    ['id' => 22, 'name' => 'الخليل'],
                ],
            ]], 200),
            'opost.ps/api/resources/areas*' => Http::response([[
                'data' => [
                    ['id' => 101, 'name' => 'المصيون'],
                ],
            ]], 200),
        ]);

        $result = app(DeliveryRateSyncService::class)->sync('opost');

        $this->assertSame(2, $result['cities']);
        $this->assertGreaterThanOrEqual(1, $result['areas']);

        $this->assertDatabaseHas('cities', ['name' => 'رام الله']);
        $this->assertDatabaseHas('cities', ['name' => 'الخليل']);
        $this->assertDatabaseHas('areas', ['name' => 'المصيون']);

        // سعر مدينة أُنشئ لكل مدينة (يُدار محليًا — 0 حتى يُضبط).
        $this->assertSame(2, DeliveryCityRate::count());
        $this->assertSame(2, City::count());
        $this->assertSame(1, Area::count());
    }
}
