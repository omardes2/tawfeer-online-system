<?php

namespace App\Support\Integrations\AdPlatform;

use App\Support\Contracts\AdPlatform\AdPlatformWriterInterface;
use Illuminate\Support\Collection;
use LogicException;

/**
 * محرّك الكتابة الافتراضي: لا يكتب شيئًا.
 *
 * وهو الافتراضي عمدًا — الوضع الطبيعي للنظام أن **لا يملك** صلاحية إنفاق مال،
 * وفتحُها قرارٌ صريح في ملف البيئة لا حالةٌ يقع فيها المرء سهوًا.
 *
 * ويرمي عند الكتابة ولا يمرّ بصمت: القراءة الفارغة نتيجةٌ مقبولة، أمّا «أوقفتُ
 * الإعلان» وهو لم يتوقّف فكذبةٌ تُبنى عليها قرارات.
 */
class NullAdPlatformWriter implements AdPlatformWriterInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function adSets(array $externalIds): Collection
    {
        return collect();
    }

    public function pause(string $adSetId): void
    {
        $this->refuse();
    }

    public function resume(string $adSetId): void
    {
        $this->refuse();
    }

    public function setDailyBudget(string $adSetId, float $amount): void
    {
        $this->refuse();
    }

    private function refuse(): never
    {
        throw new LogicException('الكتابة إلى منصّة الإعلانات غير مفعّلة — لا رمز كتابة مضبوط.');
    }
}
