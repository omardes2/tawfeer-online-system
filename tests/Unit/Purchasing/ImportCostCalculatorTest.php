<?php

namespace Tests\Unit\Purchasing;

use App\Modules\Purchasing\Services\ImportCostCalculator;
use PHPUnit\Framework\TestCase;

/**
 * معادلة تكلفة الاستيراد — الحساب وحده بلا قاعدة بيانات.
 *
 * المرجع مثالٌ محسوب يدويًا: 45 ¥ للوحدة، الرمبي 7.15 للدولار، الدولار 3.65 شيكلًا،
 * عمولة 5٪، والمتر المكعّب 180 $ وحجم الوحدة 0.012 م³.
 */
class ImportCostCalculatorTest extends TestCase
{
    private function calc(): ImportCostCalculator
    {
        return new ImportCostCalculator(fxToUsd: 7.15, usdRate: 3.65, commissionRate: 5, cbmRateUsd: 180);
    }

    public function test_the_unit_price_converts_to_dollars(): void
    {
        $this->assertEqualsWithDelta(6.2937, $this->calc()->unitPriceUsd(45), 0.0001);
    }

    public function test_the_commission_is_a_share_of_the_unit_price(): void
    {
        $this->assertEqualsWithDelta(0.3147, $this->calc()->unitCommissionUsd(45), 0.0001);
    }

    public function test_the_freight_share_comes_from_volume_not_value(): void
    {
        // 0.012 م³ × 180 $ — لا علاقة له بسعر الصنف.
        $this->assertEqualsWithDelta(2.16, $this->calc()->unitFreightUsd(0.012), 0.0001);
        $this->assertEqualsWithDelta(6.30, $this->calc()->unitFreightUsd(0.035), 0.0001);
    }

    public function test_the_real_unit_cost_in_base_currency(): void
    {
        // 6.2937 $ × 3.65 = 22.9720 ₪ — هذا ما يُذمّ للمورد.
        $this->assertEqualsWithDelta(22.9720, $this->calc()->unitCostBase(45), 0.0005);
    }

    public function test_the_landed_unit_cost_adds_commission_and_freight(): void
    {
        // (6.2937 + 0.3147 + 2.16) × 3.65 = 32.0047 ₪
        $this->assertEqualsWithDelta(32.0047, $this->calc()->landedUnitCostBase(45, 0.012), 0.0005);
        // البند الثاني من المثال: 88 ¥ بحجم 0.035 م³.
        $this->assertEqualsWithDelta(70.1643, $this->calc()->landedUnitCostBase(88, 0.035), 0.0005);
    }

    public function test_without_freight_or_commission_the_two_costs_match(): void
    {
        $calc = new ImportCostCalculator(fxToUsd: 7.15, usdRate: 3.65);

        $this->assertEqualsWithDelta($calc->unitCostBase(45), $calc->landedUnitCostBase(45, 0), 0.0001);
    }

    public function test_it_is_inactive_without_both_rates(): void
    {
        // بلا سعري صرف لا تحويل — الفاتورة محلية.
        $this->assertFalse((new ImportCostCalculator)->isActive());
        $this->assertFalse((new ImportCostCalculator(fxToUsd: 7.15))->isActive());
        $this->assertFalse((new ImportCostCalculator(usdRate: 3.65))->isActive());
        $this->assertTrue($this->calc()->isActive());
    }

    public function test_a_zero_exchange_rate_returns_zero_instead_of_dividing(): void
    {
        // حارس القسمة على صفر: رقمٌ صفريّ أسلم من خطأ وقت التشغيل.
        $this->assertSame(0.0, (new ImportCostCalculator(usdRate: 3.65))->unitPriceUsd(45));
        $this->assertSame(0.0, (new ImportCostCalculator)->unitCostBase(45));
    }

    public function test_a_negative_volume_is_ignored(): void
    {
        $this->assertSame(0.0, $this->calc()->unitFreightUsd(-5));
    }
}
