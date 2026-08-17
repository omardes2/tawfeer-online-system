<?php

namespace App\Support\Integrations\Messaging;

use App\Support\Contracts\Messaging\MessagingProviderInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * محرّك WhatsApp Cloud API.
 *
 * أربعة قرارات تحكم هذا الملف:
 *
 * 1. **الرسالة التسويقية قالبٌ معتمَد، لا نصٌّ حرّ.** خارج نافذة الأربع
 *    والعشرين ساعة التي يفتحها الزبون برسالته، ترفض المنصّة كل نصٍّ حرّ. فمن
 *    يبني حملةً على نصٍّ مكتوب يجدها تفشل بالكامل عند أول إرسالٍ حقيقي — بعد
 *    أن يكون قد بنى القائمة والحملة والتوقيت.
 *
 * 2. **لا إعادة محاولة تلقائية.** نداءٌ نجح ثم انقطع الاتصال يُنفَّذ مرّتين إن
 *    أُعيد، فتصل الرسالة مرّتين لشخصٍ واحد — وهو بالضبط ما يدفعه إلى الحجب.
 *    الفشل يُبلَّغ ويُقرّره إنسان.
 *
 * 3. **معرّف الرسالة (`wamid`) يُعاد ويُحفَظ.** به وحده تُربَط حالات التسليم
 *    والقراءة والفشل القادمة من الـwebhook برسالتها؛ وبدونه ترسل ولا تعرف ما
 *    وصل — وهو ما يجعل حراسة نسبة الفشل مستحيلة.
 *
 * 4. **الأخطاء تُنقل برموزها.** رقم الخطأ هو ما يفصل «رقمٌ لا وجود له» عن
 *    «تجاوزتَ حدّك اليومي» عن «القالب مرفوض» — وثلاثتها تتطلّب تصرّفًا مختلفًا.
 */
class WhatsAppCloudProvider implements MessagingProviderInterface
{
    private const BASE = 'https://graph.facebook.com';

    public function name(): string
    {
        return 'whatsapp_cloud';
    }

    public function isConfigured(): bool
    {
        return filled(config('messaging.whatsapp.token'))
            && filled(config('messaging.whatsapp.phone_number_id'));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function send(string $channel, string $to, string $message, array $meta = []): array
    {
        if (! $this->isConfigured()) {
            return ['status' => 'skipped', 'channel' => $channel, 'driver' => $this->name()];
        }

        $payload = filled($meta['template'] ?? null)
            ? $this->templatePayload($to, $meta)
            : $this->textPayload($to, $message);

        // بلا `retry`: انظر القرار 2 أعلاه.
        $response = Http::timeout((int) config('messaging.whatsapp.timeout', 20))
            ->withToken((string) config('messaging.whatsapp.token'))
            ->post(
                self::BASE.'/'.config('messaging.whatsapp.version', 'v21.0')
                    .'/'.config('messaging.whatsapp.phone_number_id').'/messages',
                $payload,
            );

        if ($response->failed()) {
            throw new RuntimeException($this->reason($response));
        }

        return [
            'status' => 'sent',
            'channel' => $channel,
            'driver' => $this->name(),
            // معرّف المنصّة — عليه تُبنى مطابقة حالات الـwebhook.
            'reference' => $response->json('messages.0.id'),
        ];
    }

    /**
     * حمولة رسالة قالب.
     *
     * المتغيّرات تُرسَل **بالترتيب** لا بالاسم: المنصّة ترقّمها {{1}} و{{2}}،
     * فاختلاف الترتيب يضع اسم الزبون مكان اسم الصنف بلا خطأٍ يُرفَع.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function templatePayload(string $to, array $meta): array
    {
        $params = array_values(array_map(
            fn ($value) => ['type' => 'text', 'text' => (string) $value],
            (array) ($meta['params'] ?? []),
        ));

        $template = [
            'name' => (string) $meta['template'],
            'language' => ['code' => (string) ($meta['language'] ?? config('messaging.whatsapp.default_language', 'ar'))],
        ];

        if ($params !== []) {
            $template['components'] = [['type' => 'body', 'parameters' => $params]];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ];
    }

    /**
     * حمولة نصٍّ حرّ.
     *
     * صالحةٌ **داخل نافذة الأربع والعشرين ساعة وحدها** — أي ردًّا على زبونٍ
     * راسلك، أو في رسالة اختبار إلى رقمك. وخارجها ترفضها المنصّة.
     *
     * @return array<string, mixed>
     */
    private function textPayload(string $to, string $message): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $message],
        ];
    }

    /** رسالة الخطأ برمزها — الرمز هو ما يفصل نوع الفشل عن نوع. */
    private function reason(Response $response): string
    {
        $error = $response->json('error') ?? [];

        return trim(sprintf(
            '%s%s',
            isset($error['code']) ? '['.$error['code'].'] ' : '',
            $error['message'] ?? ('http '.$response->status()),
        ));
    }
}
