<?php

namespace Database\Seeders;

use App\Modules\Foundation\Services\Settings;
use Illuminate\Database\Seeder;

/**
 * الإعدادات الافتراضية (المبدأ 9). لا قيم ثابتة في الكود — كلها من قاعدة البيانات.
 */
class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['key' => 'store.name', 'value' => 'توفير أونلاين', 'group' => 'store'],
            ['key' => 'store.email', 'value' => 'hello@tawfeer.online', 'group' => 'store'],
            ['key' => 'store.currency', 'value' => 'SAR', 'group' => 'store'],
            ['key' => 'store.currency_symbol', 'value' => 'ر.س', 'group' => 'store'],
            ['key' => 'store.default_locale', 'value' => 'ar', 'group' => 'localization'],
            ['key' => 'tax.enabled', 'value' => true, 'group' => 'tax', 'type' => 'boolean'],
            ['key' => 'tax.rate', 'value' => 15, 'group' => 'tax', 'type' => 'integer'],
        ];

        foreach ($defaults as $setting) {
            Settings::set(
                $setting['key'],
                $setting['value'],
                $setting['group'] ?? 'general',
                $setting['type'] ?? 'string',
            );
        }
    }
}
