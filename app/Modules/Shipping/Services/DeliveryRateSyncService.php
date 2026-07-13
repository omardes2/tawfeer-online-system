<?php

namespace App\Modules\Shipping\Services;

use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryCityRate;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Support\Contracts\Shipping\GeographySyncProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * مزامنة مدن/أسعار شركة التوصيل (نمط Opost) — المبدأ 13.
 * يسحب المدن عبر Driver الجغرافيا للمزوّد، ويُحدّث جدول delivery_city_rates:
 * - يملأ/يحدّث أسماء المدن ومعرّفاتها لدى المزوّد (external_code).
 * - يربط المدينة المحلّية (city_id) عند تطابق الاسم.
 * - **لا يدهس** أسعار التوصيل/الإرجاع المُدارة محليًا إلا إن أرجعها المزوّد فعلًا.
 */
class DeliveryRateSyncService
{
    /**
     * @return array{provider:string, synced:int, linked:int, priced:int}
     */
    public function sync(string $code = 'opost'): array
    {
        $provider = DeliveryProvider::firstOrCreate(
            ['code' => $code],
            ['name' => ucfirst($code), 'driver' => $code, 'is_active' => true],
        );

        $class = config("shipping.drivers.$code.geography_sync");
        if (! $class) {
            return ['provider' => $code, 'synced' => 0, 'linked' => 0, 'priced' => 0];
        }
        /** @var GeographySyncProviderInterface $driver */
        $driver = app($class);

        $synced = $linked = $priced = 0;

        // فهرس المدن المحلّية للربط بالاسم (عربي/إنجليزي، غير حسّاس لحالة الأحرف).
        $localCities = City::query()->get(['id', 'name', 'name_en']);
        $indexByName = [];
        foreach ($localCities as $c) {
            foreach ([$c->name, $c->name_en] as $n) {
                if ($n) {
                    $indexByName[mb_strtolower(trim($n))] = $c->id;
                }
            }
        }

        DB::transaction(function () use ($driver, $provider, $indexByName, &$synced, &$linked, &$priced) {
            foreach ($driver->pullCities() as $row) {
                $ext = (string) ($row['external_id'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));
                if ($ext === '' && $name === '') {
                    continue;
                }

                $cityId = $indexByName[mb_strtolower($name)]
                    ?? ($row['name_en'] ? ($indexByName[mb_strtolower(trim($row['name_en']))] ?? null) : null);

                $rate = DeliveryCityRate::firstOrNew([
                    'delivery_provider_id' => $provider->id,
                    'external_code' => $ext !== '' ? $ext : null,
                ]);

                $rate->name = $name !== '' ? $name : ($rate->name ?: (string) $ext);
                $rate->name_en = $row['name_en'] ?? $rate->name_en;
                $rate->city_id = $cityId ?? $rate->city_id;
                if ($cityId) {
                    $linked++;
                }
                if (! empty($row['currency'])) {
                    $rate->currency = strtoupper(substr((string) $row['currency'], 0, 3));
                }
                // الأسعار: تُحدَّث فقط إن أرجعها المزوّد (وإلا تبقى القيم المحلّية).
                if (isset($row['delivery_fee']) && is_numeric($row['delivery_fee'])) {
                    $rate->delivery_fee = (float) $row['delivery_fee'];
                    $priced++;
                }
                if (isset($row['return_fee']) && is_numeric($row['return_fee'])) {
                    $rate->return_fee = (float) $row['return_fee'];
                }
                $rate->synced_at = now();
                $rate->save();
                $synced++;
            }
        });

        return ['provider' => $code, 'synced' => $synced, 'linked' => $linked, 'priced' => $priced];
    }
}
