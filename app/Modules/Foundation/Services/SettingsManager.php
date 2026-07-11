<?php

namespace App\Modules\Foundation\Services;

use App\Modules\Foundation\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * طبقة الإعدادات الديناميكية (المبدأ 9 في ARCHITECTURE.md).
 *
 * تقرأ الإعدادات من قاعدة البيانات مع تخزين مؤقت، وتبطله عند أي تعديل.
 * الاستخدام عبر الـ Facade: Settings::get('store.currency', 'SAR').
 */
class SettingsManager
{
    private const CACHE_KEY = 'app.settings.all';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        return $this->cache = Cache::rememberForever(self::CACHE_KEY, function () {
            return Setting::query()->pluck('value', 'key')->toArray();
        });
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function set(string $key, mixed $value, string $group = 'general', string $type = 'string'): void
    {
        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'type' => $type],
        );

        $this->flush();
    }

    public function forget(string $key): void
    {
        Setting::query()->where('key', $key)->delete();
        $this->flush();
    }

    public function flush(): void
    {
        $this->cache = null;
        Cache::forget(self::CACHE_KEY);
    }
}
