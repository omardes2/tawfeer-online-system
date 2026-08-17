<?php

namespace App\Modules\Marketing\Services;

use App\Models\User;
use App\Modules\Ai\Services\AiContentService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Foundation\Services\Settings;
use App\Modules\Marketing\Models\AdChannel;
use App\Support\Contracts\Ai\AiContentResult;

/**
 * تأليف منشور لصنفٍ على صفحة.
 *
 * قراران يحكمان هذا الملف:
 *
 * 1. **الرابط متتبَّع دائمًا.** منشورٌ برابطٍ عاري يبيع ولا يُعرَف أنه باع:
 *    يدخل الطلب تحت «غير منسوب» فيبدو المنشور بلا أثر ويبدو الإعلان المدفوع
 *    وحده هو الذي يعمل. والوسم هنا هو نفسه الذي تقرأه نسبة الطلبات (ADR-054)،
 *    فالمنشور العضوي والإعلان المدفوع يُقاسان بالمسطرة نفسها.
 *
 * 2. **النصّ اقتراحٌ لا نشر.** الخدمة تُولّد وتحفظ مسوّدة؛ لا تنشر شيئًا على
 *    أي منصّة. النشر التلقائي يحتاج صلاحيات صفحاتٍ ومراجعة تطبيق، والأهمّ أنه
 *    يجعل خطأً في التوليد يظهر أمام الزبائن قبل أن يراه أحد.
 */
class SocialPostComposer
{
    public function __construct(private readonly AiContentService $ai) {}

    /**
     * اقتراح نصّ منشور لصنف.
     *
     * يمرّ عبر `AiContentService` لا عبر المزوّد مباشرةً: هناك حدّ المعدّل
     * وسجلّ الاستخدام والتراجع الرشيق حين يتعطّل المزوّد.
     */
    public function suggest(
        Product $product,
        string $platform = 'facebook',
        string $locale = 'ar',
        ?string $tone = null,
        ?User $actor = null,
    ): AiContentResult {
        return $this->ai->generate(
            type: 'social_caption',
            action: 'generate',
            locale: $locale,
            inputs: $this->inputs($product, $platform),
            tone: $tone,
            product: $product,
            actor: $actor,
        );
    }

    /**
     * مدخلات المنتج التي يُبنى عليها النصّ.
     *
     * السعر منها عمدًا: المنشور بلا سعرٍ يُنتج محادثاتٍ سؤالُها الأول «كم
     * السعر؟» — وهي محادثاتٌ تُحسَب على الحملة ولا تتحوّل.
     *
     * @return array<string, mixed>
     */
    private function inputs(Product $product, string $platform): array
    {
        $variant = $product->defaultVariant;
        $currency = (string) Settings::get('store.currency_symbol', '₪');

        return array_filter([
            'name' => $product->name,
            'category' => $product->category?->name,
            'brand' => $product->brand?->name,
            'short_description' => $product->short_description,
            'price' => $variant?->retail_price
                ? number_format((float) $variant->retail_price, 2).' '.$currency
                : null,
            'platform' => $platform,
            // إنستغرام يعتمد الوسوم والسطور القصيرة، وفيسبوك يحتمل نصًّا أطول.
            'notes' => $platform === 'instagram'
                ? __('منشور إنستغرام: أسطر قصيرة، وسوم في آخره، بلا روابط داخل النصّ.')
                : __('منشور فيسبوك: جملة افتتاحية تجذب، ثم المزايا، ثم دعوة للطلب.'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * رابط الصنف موسومًا بمصدره، فتُنسب طلباتُه إلى الصفحة التي نُشر عليها.
     *
     * `utm_campaign` يحمل رمز القناة بصيغة `tw-ch-{id}` — وهي الصيغة التي
     * تقرأها `AdAttributionService`. ولا يُستعمل `utm_content` هنا: ذاك محجوز
     * لمعرّف المجموعة الإعلانية، وحشوُ رمزٍ عضويّ فيه كان سيجعل النسبة تبحث
     * عن مجموعةٍ لا وجود لها.
     */
    public function trackedLink(Product $product, ?AdChannel $channel, string $platform = 'facebook'): string
    {
        $source = $platform === 'instagram' ? 'instagram' : 'facebook';

        return route('storefront.product', $product->slug).'?'.http_build_query(array_filter([
            'utm_source' => $source,
            'utm_medium' => 'organic',
            'utm_campaign' => $channel ? AdAttributionService::channelToken($channel->id) : null,
        ]));
    }
}
