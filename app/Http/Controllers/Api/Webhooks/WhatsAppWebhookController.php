<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Modules\AiAgent\Support\MessageBuffer;
use App\Modules\Marketing\Services\WhatsAppStatusService;
use App\Modules\Messaging\Services\InboundMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * استقبال حالات واتساب: أُرسلت، سُلّمت، قُرئت، فشلت.
 *
 * **بلا هذه النقطة ترسل وأنت أعمى.** المنصّة تقيس جودة رقمك بما يصل وما يُحجب،
 * وأنت لا ترى منه شيئًا إلّا من هنا — فلا سبيل لإيقافٍ تلقائي عند ارتفاع
 * الفشل، ولا لمعرفة أن رقمًا لم يعد يستقبل.
 *
 * والتحقّق من التوقيع شرطُ قبولٍ لا تحسين: نقطةٌ عامّة بلا تحقّق يستطيع أيُّ
 * أحدٍ أن يُخبرها أن رسائلك سُلّمت — أو أن يُغرقها بحالاتٍ ملفّقة تُفسد أرقامك
 * وتُوقف حملتك.
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppStatusService $statuses,
        private readonly InboundMessageService $inbound,
        private readonly MessageBuffer $agent,
    ) {}

    /**
     * تحقّق ميتا عند ربط الـwebhook — تُعيد التحدّي نصًّا خامًا.
     *
     * ولا يُقارَن الرمز بـ`===` مباشرةً: المقارنة الثابتة الزمن تمنع استنتاج
     * الرمز حرفًا حرفًا من فروق التوقيت.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('messaging.whatsapp.verify_token');
        $given = (string) $request->query('hub_verify_token', '');

        abort_if($expected === '' || ! hash_equals($expected, $given), 403);

        return response((string) $request->query('hub_challenge', ''), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->signatureValid($request)) {
            Log::warning('whatsapp.webhook.bad_signature');

            return response()->json(['status' => 'rejected'], 403);
        }

        $payload = $request->json()->all();

        $summary = $this->statuses->apply($payload);

        /*
        | الرسائل الواردة بجانب الحالات في النقطة نفسها: ميتا ترسل الاثنين إلى
        | عنوانٍ واحد، ولا سبيل لفصلهما إلّا بمحتوى الحمولة.
        |
        | والتخزين هنا لا على الطابور: الحمولة نفسها هي الرسالة، وتأجيلُها يعني
        | فقدانها إن سقط العامل قبل أن يلتقطها. أمّا **الردّ** فمؤجَّل بالكامل —
        | والـwebhook يُرجع 200 دون أن ينتظر النموذج.
        */
        $inbound = $this->inbound->apply($payload);
        $summary += ['messages' => $inbound['stored'], 'duplicates' => $inbound['duplicates']];

        foreach ($inbound['conversations'] as $conversationId) {
            $this->agent->schedule($conversationId);
        }

        /*
        | 200 دائمًا بعد قبول التوقيع: المنصّة تُعيد المحاولة على كل استجابةٍ
        | غير ناجحة، وخطأٌ عابر عندنا كان سيُنتج سيلًا من الإعادات يُغرق الخادم.
        | ما فشل تحليله يُسجَّل ولا يُطلَب ثانيةً.
        */
        return response()->json(['status' => 'ok'] + $summary);
    }

    /**
     * التوقيع من ميتا: `sha256=` + HMAC للجسم الخام بسرّ التطبيق.
     *
     * على **الجسم الخام** لا على المُحلَّل: إعادة ترميز JSON تُغيّر المسافات
     * وترتيب المفاتيح فيختلف التوقيع، ويُرفض كل شيء بلا سببٍ ظاهر.
     */
    private function signatureValid(Request $request): bool
    {
        $secret = (string) config('messaging.whatsapp.app_secret');

        if ($secret === '') {
            return false;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return $header !== '' && hash_equals($expected, $header);
    }
}
