<?php

namespace Tests\Feature\Storefront;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * تقريب iOS التلقائي عند التركيز في الحقول.
 *
 * يُكبّر Safari على iPhone الصفحةَ كلّها لحظةَ التركيز في أي حقل خطُّه أصغر من
 * ‎16px‎، ولا يعيدها بعد الخروج من الحقل — فيبقى المتجر مقصوصًا حتى يقرّبه الزبون
 * بأصابعه يدويًّا. كان النقر في مربّع البحث أعلى الصفحة يفعل ذلك تمامًا، لأن
 * ‎.sf-input‎ يرث ‎text-sm‎ أي ‎14px‎.
 *
 * الحلّ رفع الخطّ إلى ‎16px‎ في أجهزة اللمس، لا ‎user-scalable=no‎ في وسم
 * ‎viewport‎: ذلك يوقف التقريب اليدوي أيضًا ويكسر من يحتاجه لضعف بصره.
 *
 * الاختبار يحرس المصدر لا المتصفّح: لا وسيلة لقياس خطّ مُصيَّر من PHP، والقاعدة
 * سهلة الحذف سهوًا عند أي تنظيف لاحق لملف الأنماط.
 */
class MobileInputZoomTest extends TestCase
{
    use RefreshDatabase;

    private string $css;

    protected function setUp(): void
    {
        parent::setUp();
        $this->css = (string) file_get_contents(resource_path('css/storefront.css'));
    }

    public function test_touch_devices_get_a_sixteen_pixel_form_font(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media\s*\(pointer:\s*coarse\)[^{]*\{\s*[^}]*\.sf-input[^{]*\{[^}]*font-size:\s*16px/s',
            $this->css,
            'قاعدة رفع خطّ الحقول في أجهزة اللمس مفقودة — سيعود تقريب iOS عند التركيز.'
        );
    }

    public function test_viewport_still_allows_pinch_zoom(): void
    {
        $this->seed(DatabaseSeeder::class);

        $html = $this->get(route('storefront.home'))->assertOk()->getContent();

        preg_match('/<meta name="viewport" content="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, 'وسم viewport مفقود.');

        // الحلّ الكسول لتقريب iOS هو تعطيل التقريب أصلًا — وهو ما نرفضه.
        $this->assertStringNotContainsString('user-scalable=no', $m[1]);
        $this->assertStringNotContainsString('maximum-scale', $m[1]);
    }

    public function test_every_storefront_field_uses_a_covered_class(): void
    {
        // القاعدة تلاحق الأصناف الثلاثة فقط؛ حقلٌ خارجها يقع في الخطأ نفسه صامتًا.
        $uncovered = [];
        $scanned = 0;

        foreach ($this->storefrontViews() as $file) {
            foreach ($this->formTags((string) file_get_contents($file)) as $tag) {
                $scanned++;
                // خانات الاختيار والأزرار والحقول المخفية لا تُركَّز نصًّا فلا تُقرِّب.
                if (preg_match('/type="(hidden|checkbox|radio|submit|button)"/', $tag)) {
                    continue;
                }
                if (! preg_match('/sf-(input|select|textarea)/', $tag)) {
                    $uncovered[] = basename($file).': '.$tag;
                }
            }
        }

        // خطأ في النمط يجعل الفحص يمرّ على فراغ فينجح بلا معنى.
        $this->assertGreaterThan(30, $scanned, 'الفحص لم يلتقط حقول القوالب.');
        $this->assertSame([], $uncovered, 'حقول خارج أصناف ‎sf-‎ لن يشملها رفع الخطّ.');
    }

    /** @return list<string> */
    private function storefrontViews(): array
    {
        $files = [];
        foreach (['views/storefront', 'views/components/storefront'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(resource_path($dir), \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $entry) {
                if ($entry->isFile() && str_ends_with($entry->getFilename(), '.blade.php')) {
                    $files[] = $entry->getPathname();
                }
            }
        }
        sort($files);

        $this->assertNotEmpty($files, 'لم يُعثر على قوالب المتجر — الاختبار يفحص فراغًا.');

        return $files;
    }

    /**
     * وسوم الحقول كاملةً.
     *
     * القوالب تحمل تعابير Blade داخل السمات (`{{ $customer->email }}`)، فوسمٌ
     * ينتهي عند أول `>` يُقتطع في منتصفه ويبدو بلا صنف. لذا نتخطّى ما بين
     * علامات الاقتباس.
     *
     * @return list<string>
     */
    private function formTags(string $source): array
    {
        preg_match_all('/<(?:input|select|textarea)(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/s', $source, $m);

        return $m[0];
    }
}
