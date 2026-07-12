<?php

namespace App\Support\Contracts;

use App\Support\Social\SocialUserData;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * عقد المصادقة الاجتماعية (Phase 3.5 / ADR-036 — طبقة تكامل، المبدأ 13).
 * التنفيذ الفعلي عبر Socialite؛ في الاختبارات يُبدَّل بمزوّد وهمي دون OAuth حقيقي.
 */
interface SocialAuthProvider
{
    /** إعادة التوجيه إلى صفحة موافقة المزوّد (تحقّق الحالة داخل Socialite). */
    public function redirect(string $provider): RedirectResponse;

    /** قراءة المستخدم من رد المزوّد بعد الموافقة (تحقّق آمن للـcallback). */
    public function userFromCallback(string $provider): SocialUserData;
}
