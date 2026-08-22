<?php

namespace App\Http\Controllers\Admin\AiAgent;

use App\Http\Controllers\Controller;
use App\Modules\AiAgent\Services\HandoffService;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\MessagingChannel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * مفاتيح إيقاف الوكيل — العامّ والفرديّ.
 *
 * وكيلٌ يحادث الزبائن باسم الشركة يجب أن يُوقَف **في ثانية وبلا نشر**: يكفي
 * ردٌّ واحد سيّئ ليطلب صاحب النظام إسكاته فورًا، ولو كان الإيقاف يحتاج تعديل
 * ملفٍّ ورفعَه لبقي الوكيل يتكلّم طوال ذلك.
 *
 * ولذلك المفتاح على **القناة** في قاعدة البيانات لا في `.env` وحده: الأخير
 * يوقفه كذلك لكنه يحتاج وصولًا إلى الخادم.
 *
 * والصلاحيتان مفترقتان عمدًا: `ai_agent.toggle` قرارٌ إداريّ على القناة كلّها،
 * و`ai_agent.handoff` عملُ خدمةٍ يوميّ على محادثةٍ واحدة. ومن يردّ على الزبائن
 * لا يلزم أن يملك إطفاء الوكيل عن المتجر كلّه.
 */
class AgentControlController extends Controller
{
    public function __construct(private readonly HandoffService $handoff) {}

    /** تشغيل الوكيل أو إيقافه على قناةٍ كاملة. */
    public function toggleChannel(MessagingChannel $channel): RedirectResponse
    {
        $this->authorize('ai_agent.toggle');

        $channel->forceFill(['ai_enabled' => ! $channel->ai_enabled])->save();

        return back()->with('success', $channel->ai_enabled
            ? __('شُغّل الوكيل على هذه القناة.')
            : __('أُوقف الوكيل عن هذه القناة — الاستقبال والعرض ما زالا يعملان.'));
    }

    /** تسليم محادثةٍ إلى موظفة وإسكات الوكيل عنها. */
    public function handoff(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('ai_agent.handoff');

        $this->handoff->handoff(
            $conversation,
            'taken_over',
            notice: null,
            by: $request->user(),
            note: $request->string('note')->value() ?: null,
        );

        return back()->with('success', __('صارت المحادثة لك — الوكيل صامت عنها.'));
    }

    /** إعادة الوكيل إلى محادثةٍ سُلّمت — بقرارٍ صريح لا بموعد. */
    public function resume(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('ai_agent.handoff');

        $this->handoff->resume($conversation, $request->user());

        return back()->with('success', __('عاد الوكيل يردّ على هذه المحادثة.'));
    }
}
