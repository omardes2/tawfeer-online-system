<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Foundation\Models\Area;
use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\GeoProviderMapping;
use App\Modules\Foundation\Models\Governorate;
use App\Support\Contracts\Shipping\GeographySyncProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * استيراد تصنيف شركة التوصيل (مدن/مناطق) وأسعاره إلى جغرافيا النظام (المبدأ 13).
 * - المدن/المناطق تُبنى فعليًا في جداول cities/areas تحت محافظة حاوية للمزوّد،
 *   وتُربط بالمزوّد عبر geo_provider_mappings (المحلي مرجع النظام — لا تكرار عند إعادة المزامنة).
 * - الأسعار تُخزَّن لكل مدينة في delivery_city_rates، ولا تُدهس القيم المُدارة محليًا
 *   إلا إن أرجع المزوّد سعرًا فعليًا.
 */
class DeliveryRateSyncService
{
    /**
     * @return array{provider:string, cities:int, areas:int, priced:int, linked:int}
     */
    public function sync(string $code = 'opost'): array
    {
        $provider = DeliveryProvider::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code), 'driver' => $code, 'is_active' => true],
        );

        $class = config("shipping.drivers.$code.geography_sync");
        if (! $class) {
            return ['provider' => $code, 'cities' => 0, 'areas' => 0, 'priced' => 0, 'linked' => 0];
        }
        /** @var GeographySyncProviderInterface $driver */
        $driver = app($class);

        // محافظة حاوية لمدن المزوّد (اسم/رمز الدولة قابلان للضبط).
        $gov = Governorate::firstOrCreate(
            ['name' => (string) config('services.opost.country_name', 'فلسطين')],
            ['country_code' => (string) config('services.opost.country_code', 'PS'), 'is_active' => true],
        );

        $cities = $areas = $priced = $linked = 0;

        DB::transaction(function () use ($driver, $provider, $gov, &$cities, &$areas, &$priced, &$linked) {
            foreach ($driver->pullCities() as $row) {
                $ext = (string) ($row['external_id'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));
                if ($ext === '' && $name === '') {
                    continue;
                }

                $city = $this->resolveEntity(City::class, $provider->id, $ext, function () use ($gov, $name, $row, $ext) {
                    return City::firstOrCreate(
                        ['governorate_id' => $gov->id, 'name' => $name !== '' ? $name : $ext],
                        ['name_en' => $row['name_en'] ?? null, 'code' => $ext ?: null, 'is_active' => true],
                    );
                });
                $cities++;

                // سعر المدينة (أسعار المزوّد إن توفّرت، وإلا تبقى المُدارة محليًا).
                $rate = DeliveryCityRate::firstOrNew([
                    'delivery_provider_id' => $provider->id,
                    'external_code' => $ext !== '' ? $ext : null,
                ]);
                $rate->city_id = $city->id;
                $linked++;
                $rate->name = $name !== '' ? $name : $city->name;
                $rate->name_en = $row['name_en'] ?? $rate->name_en;
                if (! empty($row['currency'])) {
                    $rate->currency = strtoupper(substr((string) $row['currency'], 0, 3));
                }
                if (isset($row['delivery_fee']) && is_numeric($row['delivery_fee'])) {
                    $rate->delivery_fee = (float) $row['delivery_fee'];
                    $priced++;
                }
                if (isset($row['return_fee']) && is_numeric($row['return_fee'])) {
                    $rate->return_fee = (float) $row['return_fee'];
                }
                $rate->synced_at = now();
                $rate->save();

                // مناطق المدينة (اختياري — بعض المزوّدين لا يوفّرها).
                if ($ext !== '') {
                    foreach ($driver->pullAreas($ext) as $arow) {
                        $aExt = (string) ($arow['external_id'] ?? '');
                        $aName = trim((string) ($arow['name'] ?? ''));
                        if ($aName === '' && $aExt === '') {
                            continue;
                        }
                        $this->resolveEntity(Area::class, $provider->id, $aExt, function () use ($city, $aName, $arow, $aExt) {
                            return Area::firstOrCreate(
                                ['city_id' => $city->id, 'name' => $aName !== '' ? $aName : $aExt],
                                ['name_en' => $arow['name_en'] ?? null, 'code' => $aExt ?: null, 'is_active' => true],
                            );
                        });
                        $areas++;
                    }
                }
            }
        });

        return compact('cities', 'areas', 'priced', 'linked') + ['provider' => $code];
    }

    /**
     * يحلّ الكيان المحلي عبر تعيين المزوّد (external_id) أو ينشئه ثم يسجّل التعيين.
     *
     * @param  class-string  $type
     */
    private function resolveEntity(string $type, int $providerId, string $externalId, callable $factory): mixed
    {
        $mapping = $externalId !== ''
            ? GeoProviderMapping::where('delivery_provider_id', $providerId)
                ->where('mappable_type', $type)
                ->where('external_id', $externalId)
                ->first()
            : null;

        if ($mapping && $mapping->mappable) {
            return $mapping->mappable;
        }

        $entity = $factory();

        GeoProviderMapping::updateOrCreate(
            ['mappable_type' => $type, 'mappable_id' => $entity->getKey(), 'delivery_provider_id' => $providerId],
            [
                'external_id' => $externalId ?: null,
                'external_code' => $externalId ?: null,
                'sync_status' => 'synced',
                'last_synced_at' => now(),
                'is_active' => true,
            ],
        );

        return $entity;
    }
}
