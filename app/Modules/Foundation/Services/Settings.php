<?php

namespace App\Modules\Foundation\Services;

use Illuminate\Support\Facades\Facade;

/**
 * Facade للوصول السريع لطبقة الإعدادات الديناميكية.
 *
 * @method static array all()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static bool has(string $key)
 * @method static void set(string $key, mixed $value, string $group = 'general', string $type = 'string')
 * @method static void forget(string $key)
 * @method static void flush()
 *
 * @see SettingsManager
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingsManager::class;
    }
}
