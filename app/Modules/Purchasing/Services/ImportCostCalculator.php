<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\PurchaseInvoice;

/**
 * الآلة الحاسبة لتكلفة الاستيراد — المصدر الوحيد للمعادلة.
 *
 * تُحوّل سعر الوحدة من عملة المورد إلى العملة الأساسية، وتُحمّل عليه نصيبه من
 * عمولة المشتريات ومن الشحن البحري (حسب حجمه بالمتر المكعّب):
 *
 *   سعر الوحدة بالدولار = السعر بعملة الفاتورة ÷ سعر الصرف للدولار
 *   نصيب العمولة        = سعر الوحدة بالدولار × نسبة العمولة
 *   نصيب الشحن          = حجم الوحدة (CBM) × تكلفة المتر المكعّب بالدولار
 *   التكلفة الشاملة     = (المجموع) × سعر الدولار بالعملة الأساسية
 *
 * الواجهة تعرض النتيجة فورًا لكنها لا تُصدَّق: الخلفية تُعيد الحساب عند الحفظ.
 * وما لم يُملأ سعرا الصرف تكون الحاسبة **معطّلة** فتبقى الفاتورة المحلية كما كانت.
 */
final readonly class ImportCostCalculator
{
    /** منازل سعر الوحدة — أطول من منازل المبالغ لأن التحويل يُنتج كسورًا طويلة. */
    public const UNIT_SCALE = 4;

    public function __construct(
        /** كم وحدةً من عملة الفاتورة تساوي دولارًا واحدًا. */
        private float $fxToUsd = 0,
        /** كم من العملة الأساسية يساوي دولارًا واحدًا. */
        private float $usdRate = 0,
        /** نسبة عمولة المشتريات (٪). */
        private float $commissionRate = 0,
        /** تكلفة المتر المكعّب بالدولار. */
        private float $cbmRateUsd = 0,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (float) ($data['fx_rate_to_usd'] ?? 0),
            (float) ($data['usd_rate'] ?? 0),
            (float) ($data['commission_rate'] ?? 0),
            (float) ($data['cbm_rate_usd'] ?? 0),
        );
    }

    public static function fromInvoice(PurchaseInvoice $invoice): self
    {
        return new self(
            (float) $invoice->fx_rate_to_usd,
            (float) $invoice->usd_rate,
            (float) $invoice->commission_rate,
            (float) $invoice->cbm_rate_usd,
        );
    }

    /**
     * الحاسبة عاملة فقط بسعري صرف موجبين — بغيرهما لا معنى للتحويل، والفاتورة
     * تُعامَل كفاتورة محلية تُكتب تكلفتها بالعملة الأساسية مباشرة.
     */
    public function isActive(): bool
    {
        return $this->fxToUsd > 0 && $this->usdRate > 0;
    }

    /** سعر الوحدة بالدولار قبل أي مصاريف. */
    public function unitPriceUsd(float $foreign): float
    {
        return $this->fxToUsd > 0 ? $foreign / $this->fxToUsd : 0.0;
    }

    /** نصيب الوحدة من عمولة المشتريات بالدولار. */
    public function unitCommissionUsd(float $foreign): float
    {
        return $this->unitPriceUsd($foreign) * $this->commissionRate / 100;
    }

    /** نصيب الوحدة من الشحن البحري بالدولار — من حجمها لا من قيمتها. */
    public function unitFreightUsd(float $cbmPerUnit): float
    {
        return max($cbmPerUnit, 0) * $this->cbmRateUsd;
    }

    /** التكلفة الشاملة للوحدة بالدولار. */
    public function landedUnitCostUsd(float $foreign, float $cbmPerUnit): float
    {
        return $this->unitPriceUsd($foreign)
            + $this->unitCommissionUsd($foreign)
            + $this->unitFreightUsd($cbmPerUnit);
    }

    /** السعر الحقيقي للوحدة بالعملة الأساسية — هذا ما يُذمّ للمورد. */
    public function unitCostBase(float $foreign): float
    {
        return $this->round($this->unitPriceUsd($foreign) * $this->usdRate);
    }

    /** التكلفة الشاملة للوحدة بالعملة الأساسية — هذه قيمة المخزون الحقيقية. */
    public function landedUnitCostBase(float $foreign, float $cbmPerUnit): float
    {
        return $this->round($this->landedUnitCostUsd($foreign, $cbmPerUnit) * $this->usdRate);
    }

    /** تحويل مبلغ من العملة الأساسية إلى الدولار — لعرض الذمم بالعملتين. */
    public function baseToUsd(float $amount): float
    {
        return $this->usdRate > 0 ? round($amount / $this->usdRate, 2) : 0.0;
    }

    private function round(float $value): float
    {
        return round($value, self::UNIT_SCALE);
    }
}
