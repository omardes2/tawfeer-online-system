<?php

namespace App\Support\Integrations\AdPlatform;

/**
 * الحالة الحيّة لمجموعة إعلانية على المنصّة.
 *
 * `dailyBudget` قد يكون `null` وهذا ليس صفرًا: معناه أن الميزانية مضبوطة على
 * مستوى **الحملة** لا المجموعة (Campaign Budget Optimization). حينها لا يجوز
 * للطيّار أن يعدّل ميزانية هذه المجموعة — الكتابة ترفضها المنصّة، والأخطر أن
 * تنجح على حملةٍ كاملة فتُخفَّض ميزانية مجموعاتٍ رابحةٍ معها. يبقى الإيقاف
 * وحده متاحًا، وهو كافٍ لغرض الفرملة.
 */
final readonly class AdSetState
{
    public function __construct(
        public string $id,
        public string $name,
        /** حالة المستخدم: ACTIVE | PAUSED | DELETED | ARCHIVED */
        public string $status,
        /**
         * الحالة الفعلية بعد حساب حالة الحملة والحساب فوقها — مجموعةٌ «نشطة»
         * داخل حملةٍ موقوفة لا تصرف شيئًا، فلا معنى لإيقافها مرّة أخرى.
         */
        public string $effectiveStatus,
        public ?float $dailyBudget,
        public ?float $lifetimeBudget,
        public string $campaignId,
        public string $currency,
    ) {}

    /** أتصرف هذه المجموعة فعلًا الآن؟ */
    public function isLive(): bool
    {
        return $this->status === 'ACTIVE' && $this->effectiveStatus === 'ACTIVE';
    }

    /** أتقبل تعديل ميزانيتها على مستواها، أم ميزانيتها على مستوى الحملة؟ */
    public function hasOwnDailyBudget(): bool
    {
        return $this->dailyBudget !== null && $this->dailyBudget > 0;
    }
}
