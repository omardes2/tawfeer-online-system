<?php

namespace Tests\Feature\System;

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * انتهاء الجلسة والصفحة المتروكة مفتوحة.
 *
 * الحالة العملية: موظّف يترك نموذج طلبٍ مفتوحًا حتى تنتهي جلسته ثم يضغط «حفظ».
 * رمز الحماية (CSRF) لم يعد صالحًا، فكان يرى صفحة Laravel البيضاء
 * «Page Expired» بلا سببٍ ولا مخرج — فيظنّ أن النظام تعطّل ويضيع ما كتبه.
 *
 * ولا يُختبَر الرمز المنتهي نفسه: `ValidateCsrfToken` يتخطّى الفحص كلّه في بيئة
 * الاختبار (`runningUnitTests`)، فلا سبيل لتوليد 419 حقيقيّ منه. والمُختبَر هو
 * ما يراه المستخدم فعلًا: الصفحة التي يعرضها الإطار لأي 419.
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    /** صفحة ٤١٩ عربيةٌ تشرح وتُخرِج، لا صفحة الإطار البيضاء. */
    public function test_the_expired_page_is_arabic_and_offers_a_way_out(): void
    {
        Route::middleware('web')->get('/__expired-probe', fn () => abort(419));

        $response = $this->get('/__expired-probe');

        $response->assertStatus(419);
        $response->assertSee('انتهت صلاحية الصفحة', false);
        // «لم يحدث خطأ ولم يُحفَظ شيء»: الطمأنة نصفُ الفائدة — الموظف يظنّ النظام تعطّل.
        $response->assertSee('لم يُحفَظ شيء', false);
        // ومخرجٌ لا رسالةَ خطأ فحسب.
        $response->assertSee('تسجيل الدخول', false);
        $response->assertSee('dir="rtl"', false);
    }

    /** وتوجّه صاحب اللوحة إلى دخول اللوحة لا إلى دخول المتجر. */
    public function test_it_points_an_admin_to_the_admin_login(): void
    {
        Route::middleware('web')->get('/admin/__expired-probe', fn () => abort(419));

        $this->get('/admin/__expired-probe')->assertSee(route('login'), false);
    }

    /**
     * وعمر الجلسة المشحون يومُ عملٍ كامل لا ساعتين.
     *
     * يُفحص **الافتراضي المشحون** لا القيمة الجارية: `.env` غير متعقَّب ويختلف من
     * خادمٍ لآخر، فاختبارُ `config('session.lifetime')` يقيس إعداد الجهاز لا ما
     * يشحنه المشروع. والخمول المقصود خمولُ الطلبات لا خمولُ الموظف: من يملأ
     * نموذجًا طويلًا لا يُرسل شيئًا للخادم فتنتهي جلسته وهو يعمل.
     */
    public function test_the_shipped_session_lifetime_is_a_working_day(): void
    {
        $config = file_get_contents(base_path('config/session.php'));
        $example = file_get_contents(base_path('.env.example'));

        preg_match("/env\('SESSION_LIFETIME',\s*(\d+)\)/", $config, $fallback);
        preg_match('/^SESSION_LIFETIME=(\d+)$/m', $example, $shipped);

        $this->assertGreaterThanOrEqual(480, (int) ($fallback[1] ?? 0));
        $this->assertGreaterThanOrEqual(480, (int) ($shipped[1] ?? 0));
    }
}
