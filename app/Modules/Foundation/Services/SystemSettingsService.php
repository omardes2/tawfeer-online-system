<?php

namespace App\Modules\Foundation\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;

/**
 * إعدادات النظام الديناميكية (Production). كل الإعدادات مخزّنة في قاعدة البيانات عبر
 * SettingsManager. **الأسرار (مفاتيح API/كلمات المرور) تُخزَّن مُشفَّرة** (Crypt) ولا
 * تُعرض نصًّا؛ ترك الحقل فارغًا يُبقي القيمة الحالية. القيم غير السرّية تُخزَّن كما هي.
 */
class SystemSettingsService
{
    /** مفاتيح تُعامَل كأسرار (تُشفَّر عند التخزين، تُقنَّع عند العرض). */
    public const SECRETS = [
        'openai.key', 'mail.password', 'whatsapp.token', 'opost.api_key', 'opost.webhook_secret',
    ];

    /** مفاتيح ملفّات (تُرفع إلى القرص العام ويُخزَّن مسارها). */
    public const FILES = ['store.logo', 'store.favicon'];

    public function __construct(private readonly SettingsManager $settings) {}

    /**
     * القيم الحالية للعرض (الأسرار مُقنَّعة: true إن كانت مضبوطة).
     *
     * @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function values(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (in_array($key, self::SECRETS, true)) {
                $out[$key] = $this->settings->has($key) && $this->settings->get($key) ? true : false;
            } else {
                $out[$key] = $this->settings->get($key);
            }
        }

        return $out;
    }

    /**
     * حفظ دفعة إعدادات مُتحقَّق منها. الأسرار تُشفَّر (فارغ = إبقاء)، الملفّات تُرفع.
     *
     * @param  array<string, mixed>  $data  key => value
     * @param  array<string, UploadedFile|null>  $files  key => file
     * @param  array<string, string>  $groups  key => group
     */
    public function save(array $data, array $files = [], array $groups = []): void
    {
        foreach ($data as $key => $value) {
            $group = $groups[$key] ?? explode('.', $key)[0];

            if (in_array($key, self::SECRETS, true)) {
                if ($value === null || $value === '') {
                    continue; // إبقاء السرّ الحالي.
                }
                $this->settings->set($key, Crypt::encryptString((string) $value), $group, 'secret');

                continue;
            }

            $this->settings->set($key, $value, $group, is_bool($value) ? 'boolean' : 'string');
        }

        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $path = $file->store('branding', 'public');
                $this->settings->set($key, $path, explode('.', $key)[0], 'file');
            }
        }
    }

    /** قراءة سرّ مفكوك التشفير للاستخدام وقت التشغيل (جسر الإعدادات فقط). */
    public function secret(string $key): ?string
    {
        $raw = $this->settings->get($key);
        if (! $raw) {
            return null;
        }
        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            return null; // قيمة تالفة/غير مشفّرة — تجاهل بأمان.
        }
    }
}
