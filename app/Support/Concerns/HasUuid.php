<?php

namespace App\Support\Concerns;

use Illuminate\Support\Str;

/**
 * يمنح النموذج معرّفًا خارجيًا (UUID) مع إبقاء المفتاح الرقمي الداخلي (id) للأداء.
 *
 * المبدأ 4 في ARCHITECTURE.md: نستخدم UUID للكيانات المكشوفة خارجيًا (روابط/API)
 * بينما تبقى العلاقات الداخلية على BIGINT.
 *
 * يتطلّب وجود عمود `uuid` (فريد، مفهرس) في جدول النموذج.
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getUuidColumn()})) {
                $model->{$model->getUuidColumn()} = (string) Str::uuid();
            }
        });
    }

    public function getUuidColumn(): string
    {
        return 'uuid';
    }

    /**
     * ربط المسارات عبر UUID افتراضيًا (لا نكشف الـ id في الروابط العامة).
     */
    public function getRouteKeyName(): string
    {
        return $this->getUuidColumn();
    }
}
