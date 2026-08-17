<?php

namespace App\Modules\Marketing\Services;

use App\Modules\Marketing\Models\AdChannel;
use App\Modules\Marketing\Models\AdExternalMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * من أيّ إعلانٍ جاء هذا الطلب؟
 *
 * السؤال بديهيّ ولا جواب له في نظامٍ يبيع عبر الرسائل: هناك تُعرَف الصفحة من
 * الموظفة التي أدخلت الطلب. أمّا الطلب الإلكتروني فلا موظّف له، والمنصّة **لا
 * تُخبرك بالحملة من `fbclid`** — تعطيك معرّف نقرةٍ يصلح للمطابقة لا للنسبة.
 *
 * فالسبيل الوحيد أن يحمل **رابط الإعلان نفسه** معرّفاته: تدعم المنصّة معاملاتٍ
 * ديناميكية تُملأ عند العرض، فيُوضَع في رابط الإعلان:
 *
 *     ?utm_source=facebook&utm_campaign={{campaign.id}}&utm_content={{adset.id}}
 *
 * ومن `utm_content` نعرف المجموعة الإعلانية، ومنها الصنف والصفحة عبر
 * `ad_external_maps` — وهو الربط نفسه الذي يقوم عليه سحب الصرف، فلا مصدرَ
 * ثانٍ للحقيقة.
 *
 * والحفظ في **كعكة** لا في الجلسة: الزائر يصل من الإعلان اليوم ويشتري بعد
 * يومين، وجلسةُ الضيف قد تُجدَّد بينهما فتضيع النسبة بصمت.
 */
class AdAttributionService
{
    /** اسم الكعكة — مشفَّرة كسائر كعكات Laravel. */
    public const COOKIE = 'tw_ad_attr';

    /** مدّة الاحتفاظ بالنسبة (بالدقائق) — 30 يومًا، نافذة النسبة المعتادة. */
    private const TTL = 60 * 24 * 30;

    /**
     * التقاط معاملات الإعلان من زيارةٍ، إن وُجدت.
     *
     * **آخر نقرةٍ تفوز**: الزائر قد يصل من إعلانين، والشراء يُنسَب لما جاء به
     * أخيرًا — وهو نموذج المنصّة نفسه، ومخالفتُه تجعل أرقامنا تفترق عن أرقامها.
     *
     * @return array<string, string>|null
     */
    public function capture(Request $request): ?array
    {
        $data = array_filter([
            'click_id' => $this->clickId($request),
            'source' => $this->clean($request->query('utm_source'), 40),
            'campaign' => $this->clean($request->query('utm_campaign'), 64),
            'adset' => $this->clean($request->query('utm_content'), 64),
        ], fn (?string $v) => $v !== null && $v !== '');

        // لا نقرة ولا وسم: زيارةٌ عادية لا تمسّ ما هو محفوظ.
        if ($data === []) {
            return null;
        }

        Cookie::queue(Cookie::make(self::COOKIE, json_encode($data), self::TTL));

        return $data;
    }

    /**
     * النسبة المحفوظة لهذا الزائر.
     *
     * @return array<string, string>
     */
    public function stored(?Request $request = null): array
    {
        $request ??= request();
        $raw = $request?->cookie(self::COOKIE);

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? array_map(fn ($v) => (string) $v, $data) : [];
    }

    /** رمز القناة في روابط المنشورات العضوية — `tw-ch-7`. */
    public static function channelToken(int $channelId): string
    {
        return 'tw-ch-'.$channelId;
    }

    /**
     * القناة من رمزٍ عضويّ في `utm_campaign`.
     *
     * المنشور على صفحةٍ ليس إعلانًا مدفوعًا ولا مجموعة إعلانية له، لكنّنا نعرف
     * الصفحة التي نشرناه عليها يقينًا — فيُوضَع رمزُها في الرابط. وبلا هذا كان
     * المنشور العضويّ يبيع ولا يُعرَف أنه باع، فيبدو الإعلان المدفوع وحده هو
     * الذي يعمل.
     *
     * والقناة تُتحقَّق من وجودها: رمزٌ لقناةٍ محذوفة لا يُنسَب إليه شيء.
     */
    public function resolveChannelFromToken(?string $campaignRef): ?int
    {
        if (! $campaignRef || ! preg_match('/^tw-ch-(\d+)$/', $campaignRef, $m)) {
            return null;
        }

        return AdChannel::whereKey((int) $m[1])->value('id');
    }

    /**
     * القناة (الصفحة) التي تخصّ مجموعةً إعلانية.
     *
     * المسار: المجموعة ← حملتها الأمّ ← الصفحة المربوطة بها. ومجموعةٌ غير
     * مربوطة تعطي `null` ولا تُخمَّن — النسبة الخاطئة أسوأ من غيابها، لأن
     * غيابها ظاهرٌ في التقرير وخطأها ليس كذلك.
     */
    public function resolveChannelId(?string $adSetRef): ?int
    {
        if (! $adSetRef) {
            return null;
        }

        $adSet = AdExternalMap::query()
            ->where('external_type', AdExternalMap::TYPE_ADSET)
            ->where('external_id', $adSetRef)
            ->first();

        if (! $adSet?->parent_external_id) {
            return null;
        }

        return AdExternalMap::query()
            ->where('external_type', AdExternalMap::TYPE_CAMPAIGN)
            ->where('external_id', $adSet->parent_external_id)
            ->value('ad_channel_id');
    }

    /**
     * معرّف النقرة بالصيغة التي تفهمها المنصّة (`fb.1.{ms}.{fbclid}`).
     *
     * يُبنى هنا لا عند الإرسال: الصيغة تتضمّن **لحظة وصول النقرة**، وبناؤها وقت
     * الطلب كان سيضع لحظة الشراء مكان لحظة النقرة — فتضعف المطابقة أو تسقط.
     * وهي الصيغة نفسها التي يكتبها بكسل المتصفّح في كعكة `_fbc`، فلا يتضاربان.
     */
    private function clickId(Request $request): ?string
    {
        $fbclid = $this->clean($request->query('fbclid'), 200);

        return $fbclid ? 'fb.1.'.(time() * 1000).'.'.$fbclid : null;
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }
}
