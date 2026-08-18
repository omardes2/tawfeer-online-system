<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

/**
 * زرّ «شراء الآن» — أصنافه تصل إلى الصفحة.
 *
 * كان على الزرّ توجيهان `@class`، وكلٌّ منهما يُصيّر السمة `class` كاملةً.
 * والمتصفّح يأخذ الأولى ويُهمل الثانية، فوصلت الأولى فارغة (`triggerless`
 * منطفئة) وسقطت أصناف الشكل كلّها: نصٌّ عارٍ وأيقونةٌ في سطرٍ وحدها بدل زرّ —
 * في صفحة المنتج على المتجر الحيّ.
 *
 * وهو عطلٌ لا يكشفه أي اختبار سلوك: الزرّ يعمل، والمسار سليم، والشكل وحده ضاع.
 */
class QuickBuyButtonStyleTest extends TestCase
{
    private function render(array $props = []): string
    {
        $attrs = collect($props)->map(fn ($v, $k) => is_bool($v) ? ($v ? $k : '') : $k.'="'.$v.'"')
            ->filter()->implode(' ');

        return (string) $this->blade('<x-storefront.quick-buy '.$attrs.' />');
    }

    /**
     * وسمُ الزرّ الفاتح وحده.
     *
     * المكوّن يحمل أزرارًا أخرى داخل اللوح (الإغلاق، تأكيد الطلب) وبعضها يشترك
     * في الأصناف — ففحصُ الصفحة كلّها يمرّ وإن ضاع شكلُ زرّنا.
     */
    private function triggerTag(array $props = []): string
    {
        preg_match('/<button[^>]*>/', $this->render($props), $m);

        $this->assertNotEmpty($m, 'لم يُعثر على زرّ في مخرجات المكوّن.');

        return $m[0];
    }

    /** السمة `class` لا تتكرّر على الزرّ — تكرارُها يُسقط الثانية بصمت. */
    public function test_the_button_renders_a_single_class_attribute(): void
    {
        $tag = $this->triggerTag(['variant' => 'v-1']);

        $this->assertSame(1, substr_count($tag, 'class='), 'سمة class مكرّرة — المتصفّح يُهمل الثانية.');
    }

    /** والشكل يصل: زرٌّ بإطارٍ كامل العرض لا نصٌّ عارٍ. */
    public function test_the_default_button_carries_its_style_classes(): void
    {
        $tag = $this->triggerTag(['variant' => 'v-1']);

        $this->assertStringContainsString('sf-btn-outline', $tag);
        $this->assertStringContainsString('sf-btn-block', $tag);
    }

    /** والمضغوط (الشريط اللاصق) يحمل شكله هو. */
    public function test_the_compact_button_carries_its_own_style(): void
    {
        $tag = $this->triggerTag(['variant' => 'v-1', 'compact' => true]);

        $this->assertStringContainsString('sf-btn-primary', $tag);
        $this->assertStringNotContainsString('sf-btn-block', $tag);
    }

    /** وبلا زرّ (بطاقة العروض تفتحه بالحدث) يبقى مخفيًّا. */
    public function test_the_triggerless_button_stays_hidden(): void
    {
        $tag = $this->triggerTag(['variant' => 'v-1', 'triggerless' => true]);

        $this->assertStringContainsString('hidden', $tag);
    }
}
