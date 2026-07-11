<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Unit;
use Illuminate\Database\Seeder;

/**
 * وحدات قياس افتراضية (بيانات مرجعية). تبقى قابلة للإدارة من اللوحة.
 */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['name' => 'قطعة', 'name_en' => 'Piece', 'code' => 'PCS', 'symbol' => 'قطعة'],
            ['name' => 'كرتون', 'name_en' => 'Carton', 'code' => 'CTN', 'symbol' => 'كرتون'],
            ['name' => 'كيلوغرام', 'name_en' => 'Kilogram', 'code' => 'KG', 'symbol' => 'كجم'],
            ['name' => 'لتر', 'name_en' => 'Liter', 'code' => 'LTR', 'symbol' => 'لتر'],
            ['name' => 'متر', 'name_en' => 'Meter', 'code' => 'MTR', 'symbol' => 'م'],
        ];

        foreach ($units as $unit) {
            Unit::query()->firstOrCreate(
                ['code' => $unit['code']],
                array_merge($unit, ['conversion_factor' => 1, 'is_active' => true]),
            );
        }
    }
}
