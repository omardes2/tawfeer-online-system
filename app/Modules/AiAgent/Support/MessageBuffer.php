<?php

namespace App\Modules\AiAgent\Support;

use App\Jobs\DispatchAgentReply;
use App\Modules\Messaging\Models\Conversation;
use Illuminate\Support\Facades\Cache;

/**
 * تجميع الرسائل المتتالية في ردٍّ واحد.
 *
 * الزبون يرسل ثلاث رسائل في ثانيتين — «مرحبا» ثم «بدي أسأل» ثم «هذا بكم؟» —
 * والردّ على كل واحدةٍ منفردة يبدو آليًّا وغبيًّا، ويُنفق ثلاثة استدعاءاتٍ
 * للنموذج على سؤالٍ واحد.
 *
 * الآلية: **رمز جيل** لكل محادثة. كل رسالةٍ جديدة ترفع الرمز وتجدول مهمّةً
 * مؤجَّلة تحمله؛ فحين تعمل المهمّة تقارن رمزها بالحاليّ — إن اختلف فقد جاءت
 * رسالةٌ بعدها وهي ليست الأخيرة، فتنسحب صامتة.
 *
 * ولمَ لا يُلغى الجدولُ السابق مباشرةً؟ لأن الطوابير لا تدعم إلغاء مهمّةٍ
 * مجدولة إلغاءً موثوقًا عبر كل المحرّكات. والمقارنة تعمل على أيّ محرّك.
 *
 * و**سقف الانتظار الكلّي** يمنع تأجيلًا بلا نهاية: من يكتب بلا توقّف كان
 * سيؤجَّل ردُّه إلى الأبد، فيُثبَّت أول موعدٍ ويُردّ عنده مهما تتابعت رسائله.
 */
class MessageBuffer
{
    private const GENERATION = 'ai-agent:generation:';

    private const DEADLINE = 'ai-agent:deadline:';

    /**
     * جدولة ردٍّ على محادثة بعد مهلة التجميع.
     *
     * تُستدعى من الـwebhook لكل رسالةٍ واردة.
     */
    public function schedule(int $conversationId): void
    {
        $conversation = Conversation::with('contact.channel')->find($conversationId);

        // لا جدولة لمحادثةٍ لا يردّ عليها الوكيل: المفاتيح الثلاثة تُفحص هنا
        // قبل إنفاق مهمّةٍ في الطابور، لا بعد أن تعمل.
        if ($conversation === null || ! $conversation->agentMayReply()) {
            return;
        }

        $generation = (int) Cache::increment(self::GENERATION.$conversationId);
        Cache::put(self::GENERATION.$conversationId, $generation, now()->addHour());

        $delay = (int) config('ai_agent.debounce_seconds', 5);
        $maxWait = (int) config('ai_agent.max_wait_seconds', 20);

        // أول موعدٍ يُثبَّت ولا يتحرّك: به يُقطع التأجيل اللانهائي.
        $deadline = Cache::remember(
            self::DEADLINE.$conversationId,
            now()->addSeconds($maxWait + 60),
            fn () => now()->addSeconds($maxWait)->timestamp,
        );

        $runAt = now()->addSeconds($delay);
        if ($runAt->timestamp > $deadline) {
            $runAt = now()->setTimestamp($deadline);
        }

        DispatchAgentReply::dispatch($conversationId, $generation)->delay($runAt);
    }

    /** هل ما زال هذا الجيل هو الأحدث؟ */
    public function isCurrent(int $conversationId, int $generation): bool
    {
        return (int) Cache::get(self::GENERATION.$conversationId, 0) === $generation;
    }

    /** تُنسى حالة التجميع بعد الردّ، فيبدأ الدور التالي نظيفًا. */
    public function clear(int $conversationId): void
    {
        Cache::forget(self::GENERATION.$conversationId);
        Cache::forget(self::DEADLINE.$conversationId);
    }
}
