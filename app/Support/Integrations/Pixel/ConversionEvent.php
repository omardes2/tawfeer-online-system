<?php

namespace App\Support\Integrations\Pixel;

/**
 * حدث تحويل واحد، بصيغةٍ مستقلّة عن المنصّة.
 *
 * `eventId` ليس زينة: هو ما يمنع احتساب الشراء مرّتين حين يصل الحدث من
 * المتصفّح ومن الخادم معًا، وهو أيضًا ما يجعل إعادة المحاولة بعد فشل الشبكة
 * آمنة. يُشتقّ من الطلب لا يُولَّد عشوائيًّا، فإعادةُ إرسال الحدث نفسه تحمل
 * المعرّف نفسه دائمًا.
 *
 * وبيانات الزبون تُمرَّر خامًا هنا وتُجزَّأ (hash) في المحرّك: التجزئة تفصيلٌ
 * يخصّ المنصّة، ووضعُها هنا كان سيمنع أي محرّكٍ آخر من استعمال البيانات.
 */
final readonly class ConversionEvent
{
    /**
     * @param  array<int, array{id: string, quantity: int, item_price: float}>  $contents
     */
    public function __construct(
        public string $name,
        public string $eventId,
        public int $eventTime,
        public ?string $sourceUrl = null,
        public ?string $email = null,
        public ?string $phone = null,
        /** معرّف النقرة بصيغة `fb.1.{ms}.{fbclid}` — أقوى إشارات المطابقة. */
        public ?string $clickId = null,
        public ?string $browserId = null,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?float $value = null,
        public ?string $currency = null,
        public array $contents = [],
    ) {}
}
