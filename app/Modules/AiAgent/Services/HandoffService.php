<?php

namespace App\Modules\AiAgent\Services;

use App\Models\User;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Services\OutboundMessageService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * تسليم المحادثة إلى إنسان.
 *
 * الفعل الوحيد الذي **يُسكت الوكيل**، ولذلك هو مركزيٌّ لا مكرّر: يستدعيه
 * الوكيل حين يعجز، ويستدعيه المشغّل حين يفشل، وستستدعيه الموظفة من الصندوق.
 * ولو كُتب في كلٍّ منها على حدة لاختلفت المواضع في تفصيلٍ واحد — يُنسى فيها
 * ضبط `ai_mode` مثلًا — فيعود الوكيل يتكلّم في محادثةٍ سُلّمت.
 *
 * و`handed_off` لا `paused`: الأول لا يعود إلّا بقرارٍ صريح، والثاني يعود
 * تلقائيًّا. ومحادثةٌ حُوّلت لغضبٍ أو لعجزٍ لا يجوز أن يعود إليها الوكيل وحده.
 */
class HandoffService
{
    public function __construct(private readonly OutboundMessageService $outbound) {}

    /**
     * @param  string  $reason  سببٌ قصير يُقرأ في الصندوق: tool_limit | agent_error | empty_reply | requested
     * @param  string|null  $notice  رسالةٌ للزبون. تُترك فارغةً حين تكون الموظفة حاضرةً تكتب بنفسها.
     */
    public function handoff(
        Conversation $conversation,
        string $reason,
        ?string $notice = null,
        ?User $by = null,
        ?string $note = null,
    ): Conversation {
        // الحالة تُضبط **قبل** الإرسال: لو أُرسل أولًا وانقطع الاتصال، بقي
        // الوكيل نشطًا في محادثةٍ اعتذر فيها للتوّ — فيردّ بعد الاعتذار.
        $conversation->forceFill([
            'ai_mode' => Conversation::AI_HANDED_OFF,
            // السبب مفتاحٌ ثابت، والسطر الحرّ للموظفة يُلحق به بفاصلة: عمودٌ
            // واحد يحمل ما يُقرأ إحصاءً وما يُقرأ نصًّا.
            'handoff_reason' => filled($note) ? $reason.' — '.mb_substr((string) $note, 0, 180) : $reason,
            'handoff_at' => now(),
        ])->save();

        if (filled($notice)) {
            $this->notify($conversation, (string) $notice, $by);
        }

        return $conversation;
    }

    /**
     * إعادة الوكيل إلى محادثةٍ سُلّمت — **بقرار إنسان وحده**.
     *
     * لا موعدَ يُعيده ولا شرطَ آليّ: من حوّل يعرف لماذا حوّل، ومن يعيده يتحمّل
     * أن يتكلّم الوكيل ثانيةً مع زبونٍ ربما كان غاضبًا.
     */
    public function resume(Conversation $conversation, ?User $by = null): Conversation
    {
        $conversation->forceFill([
            'ai_mode' => Conversation::AI_ACTIVE,
            'handoff_reason' => null,
            'handoff_at' => null,
            'assigned_user_id' => $conversation->assigned_user_id ?? $by?->id,
        ])->save();

        return $conversation;
    }

    /**
     * إيقافٌ مؤقّت لمحادثةٍ واحدة — تعود بقرارٍ صريح كذلك.
     *
     * يفترق عن التحويل في المعنى لا في الأثر: `paused` تقول «الموظفة تكتب
     * الآن»، و`handed_off` تقول «هذه المحادثة صارت لها». والخلط بينهما يُفقد
     * الصندوق قدرته على فرز ما يحتاج متابعة.
     */
    public function pause(Conversation $conversation): Conversation
    {
        $conversation->forceFill(['ai_mode' => Conversation::AI_PAUSED])->save();

        return $conversation;
    }

    /**
     * إبلاغ الزبون — بحمايته الخاصّة.
     *
     * فشل الاعتذار (النافذة أُغلقت، أو واتساب لا يستجيب) يجب ألّا ينقض
     * التحويل: أسوأ ما يقع أن يبقى الزبون عند وكيلٍ معطوب لأن رسالة الاعتذار
     * لم تُرسَل.
     */
    private function notify(Conversation $conversation, string $notice, ?User $by): void
    {
        try {
            $this->outbound->sendText($conversation, $notice, $by === null ? 'system' : 'agent', $by);
        } catch (Throwable $e) {
            Log::warning('ai_agent.handoff.notice_failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
