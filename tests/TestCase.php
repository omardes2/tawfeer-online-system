<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // الأصول المبنيّة (`public/build`) خارج المستودع، فأيّ اختبارٍ يعرض صفحة
        // كان يسقط بـ«Vite manifest not found» على نسخةٍ لم يُشغَّل فيها
        // `npm run build` — يفشل الاختبار لسببٍ لا علاقة له بما يفحصه.
        // والاختبار يفحص ما تقوله الصفحة لا كيف تُصمَّم، فلا حاجة لأصولٍ مبنيّة.
        $this->withoutVite();
    }
}
