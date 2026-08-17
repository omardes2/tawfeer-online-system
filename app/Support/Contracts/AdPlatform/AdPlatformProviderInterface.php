<?php

namespace App\Support\Contracts\AdPlatform;

use App\Support\Integrations\AdPlatform\AdInsightRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * عقد منصّة إعلانات (المبدأ 12 — طبقة تكامل بعقدٍ ومحرّكات).
 *
 * العقد **للقراءة وحدها**: يجلب ما صُرف وما نتج، ولا يُنشئ حملةً ولا يوقفها ولا
 * يغيّر ميزانية. ولو احتاج النظام يومًا أن يكتب إلى المنصّة فبعقدٍ منفصل لا
 * بتوسيع هذا — فصلٌ مقصود: رمزُ القراءة لا يملك صلاحية الصرف، فخطأٌ في
 * الاستيراد لا يمكن أن يُنفق مالًا.
 *
 * ولا يُستدعى محرّك مباشرةً من متحكّم أو خدمة أعمال — فقط عبر `AdPlatformManager`.
 */
interface AdPlatformProviderInterface
{
    /** اسم المحرّك للسجلّات والواجهة. */
    public function name(): string;

    /**
     * هل المحرّك مضبوط وجاهز للنداء؟
     *
     * تُفحَص قبل كل مزامنة: المحرّك غير المضبوط يتوقّف بهدوء (no-op) ولا يرمي —
     * فالمهمّة المجدولة لا يجوز أن تفشل ليليًّا على نظامٍ لم يُربَط بعد.
     */
    public function isConfigured(): bool;

    /**
     * صرفُ كل مجموعة إعلانية في كل يوم ضمن المدى، ونتائجها.
     *
     * صفٌّ لكل (يوم × مجموعة إعلانية)، بالمعرّفات الخارجية لا بالأسماء —
     * الاسم يتغيّر والمعرّف لا يتغيّر، وعليه يقوم الربط.
     *
     * @return Collection<int, AdInsightRow>
     */
    public function dailyInsights(Carbon $from, Carbon $to): Collection;
}
