<?php

namespace App\Support\Integrations\Pixel;

use App\Support\Contracts\Pixel\ConversionTrackerInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * محرّك Meta Conversions API.
 *
 * ثلاثة قرارات:
 *
 * 1. **كل ما يُعرّف الزبون يُجزَّأ (SHA-256) قبل مغادرته الخادم.** المنصّة تشترطه،
 *    ونحن نلتزمه لسببٍ أسبق: بريدُ الزبون ورقمه أمانةٌ لا تُرسَل خامًا إلى طرفٍ
 *    ثالث. والتطبيع قبل التجزئة ليس تفصيلًا — «0599…» و«+970599…» يعطيان
 *    تجزئتين مختلفتين لشخصٍ واحد، فتسقط المطابقة بلا أن يشتكي أحد.
 *
 * 2. **`event_id` يُرسَل دائمًا.** به تُلغي المنصّة ازدواج الحدث الواصل من
 *    المتصفّح والخادم، وبه تصير إعادةُ المحاولة بعد انقطاع الشبكة آمنة.
 *
 * 3. **لا تُرسَل حقولٌ فارغة.** المنصّة تعدّ الحقل الفارغ إشارةَ مطابقةٍ رديئة
 *    وتُنقص درجة الجودة، والحذف أصدق من إرسال فراغ.
 */
class MetaConversionsApiTracker implements ConversionTrackerInterface
{
    private const BASE = 'https://graph.facebook.com';

    public function name(): string
    {
        return 'meta';
    }

    public function isConfigured(): bool
    {
        return filled(config('ads.pixel.id')) && filled(config('ads.pixel.token'));
    }

    public function track(ConversionEvent $event): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $payload = [
            'data' => [array_filter([
                'event_name' => $event->name,
                'event_id' => $event->eventId,
                'event_time' => $event->eventTime,
                'action_source' => 'website',
                'event_source_url' => $event->sourceUrl,
                'user_data' => $this->userData($event),
                'custom_data' => $this->customData($event),
            ], fn ($v) => $v !== null && $v !== [])],
        ];

        // رمز اختبار الأحداث — يُملأ أثناء الضبط ليظهر الحدث في أداة الفحص.
        if (filled($code = config('ads.pixel.test_event_code'))) {
            $payload['test_event_code'] = $code;
        }

        $response = Http::timeout((int) config('ads.pixel.timeout', 15))
            ->post(
                self::BASE.'/'.config('ads.pixel.version', 'v21.0').'/'.config('ads.pixel.id').'/events'
                    .'?access_token='.urlencode((string) config('ads.pixel.token')),
                $payload,
            );

        if ($response->failed()) {
            // الرسالة تفيد التشخيص (بكسل خاطئ، رمز منتهٍ، حقل مرفوض) بلا الرمز.
            throw new RuntimeException('تعذّر إرسال حدث التحويل: '
                .($response->json('error.message') ?? 'http '.$response->status()));
        }
    }

    /** @return array<string, mixed> */
    private function userData(ConversionEvent $event): array
    {
        return array_filter([
            'em' => $this->hash($this->normalizeEmail($event->email)),
            'ph' => $this->hash($this->normalizePhone($event->phone)),
            // معرّفا النقرة والمتصفّح لا يُجزّآن — تُرسَل كما هي بنصّ المنصّة.
            'fbc' => $event->clickId,
            'fbp' => $event->browserId,
            'client_ip_address' => $event->ip,
            'client_user_agent' => $event->userAgent,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    private function customData(ConversionEvent $event): array
    {
        return array_filter([
            'value' => $event->value,
            'currency' => $event->currency,
            'contents' => $event->contents ?: null,
            'content_type' => $event->contents ? 'product' : null,
        ], fn ($v) => $v !== null);
    }

    private function hash(?string $value): ?string
    {
        return $value ? hash('sha256', $value) : null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        return $email ? mb_strtolower(trim($email)) : null;
    }

    /**
     * الهاتف بصيغةٍ دولية بلا رموز.
     *
     * الأرقام المحلّية تُدخَل «0599…»، والمنصّة تتوقّع «970599…». وبلا هذا
     * التحويل يُجزَّأ رقمٌ مختلف عن الذي عند المنصّة فلا يُطابَق أحد.
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        $country = (string) config('ads.pixel.country_code', '970');

        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            return $country.ltrim($digits, '0');
        }

        return str_starts_with($digits, $country) ? $digits : $country.$digits;
    }
}
