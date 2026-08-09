<?php

namespace Tests\Unit\Shipping;

use App\Support\Integrations\Shipping\OpostDeliveryProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpostBusinessPayloadTest extends TestCase
{
    public function test_create_shipment_prefers_payload_business_over_config(): void
    {
        config([
            'services.opost.token' => 'static-token',
            'services.opost.client_id' => null,
            'services.opost.username' => null,
            'services.opost.business_id' => 'CONFIG_BIZ',
            'services.opost.business_address_id' => 'CONFIG_ADDR',
            'services.opost.base_url' => 'https://opost.ps/api',
        ]);

        Http::fake(['*' => Http::response(['data' => ['id' => 1, 'barcode' => 'BC1']], 200)]);

        $result = (new OpostDeliveryProvider)->createShipment([
            'consignee_name' => 'زبون', 'consignee_phone' => '0599000000',
            'business_external_id' => 'USER_BIZ', 'business_address_external_id' => 'ADDR9',
        ]);

        $this->assertSame('created', $result['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/resources/shipments')
                && ($request['business'] ?? null) === 'USER_BIZ'
                && ($request['business_address'] ?? null) === 'ADDR9';
        });
    }

    public function test_create_shipment_falls_back_to_config_business(): void
    {
        config([
            'services.opost.token' => 'static-token',
            'services.opost.client_id' => null,
            'services.opost.username' => null,
            'services.opost.business_id' => 'CONFIG_BIZ',
            'services.opost.base_url' => 'https://opost.ps/api',
        ]);

        Http::fake(['*' => Http::response(['data' => ['id' => 2, 'barcode' => 'BC2']], 200)]);

        (new OpostDeliveryProvider)->createShipment([
            'consignee_name' => 'زبون', 'consignee_phone' => '0599000000',
        ]);

        Http::assertSent(fn ($request) => ($request['business'] ?? null) === 'CONFIG_BIZ');
    }
}
