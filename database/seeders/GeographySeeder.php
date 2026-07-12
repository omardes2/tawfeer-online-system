<?php

namespace Database\Seeders;

use App\Modules\Foundation\Models\City;
use App\Modules\Foundation\Models\DeliveryProvider;
use App\Modules\Foundation\Models\Governorate;
use Illuminate\Database\Seeder;

/**
 * بذور جغرافية أساسية (§187: "البذور تُزرع إداريًا الآن") + مزوّد توصيل افتراضي (null).
 */
class GeographySeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'الرياض' => ['الرياض', 'الدرعية', 'الخرج'],
            'مكة المكرمة' => ['مكة المكرمة', 'جدة', 'الطائف'],
            'المنطقة الشرقية' => ['الدمام', 'الخبر', 'الأحساء'],
        ];

        $sort = 1;
        foreach ($data as $govName => $cities) {
            $gov = Governorate::query()->firstOrCreate(
                ['name' => $govName],
                ['country_code' => 'SA', 'sort_order' => $sort++],
            );

            $citySort = 1;
            foreach ($cities as $cityName) {
                City::query()->firstOrCreate(
                    ['governorate_id' => $gov->id, 'name' => $cityName],
                    ['sort_order' => $citySort++],
                );
            }
        }

        DeliveryProvider::query()->firstOrCreate(
            ['code' => 'null'],
            ['name' => 'بدون مزوّد (افتراضي)', 'driver' => 'null', 'is_active' => true],
        );
    }
}
