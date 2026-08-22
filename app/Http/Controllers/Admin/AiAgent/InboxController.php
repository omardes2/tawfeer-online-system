<?php

namespace App\Http\Controllers\Admin\AiAgent;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\ConversationStatus;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessagingChannel;
use App\Modules\Messaging\Services\OutboundMessageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * الصندوق الموحّد — محادثات واتساب وما قاله الوكيل فيها.
 *
 * أوّل شاشةٍ يرى فيها صاحب النظام وكيلَه يعمل. وغرضها الأول **الرقابة** لا
 * الخدمة: أن يُقرأ ما قاله الوكيل بالحرف، وأن يُوقَف بضغطةٍ حين يخطئ.
 *
 * والقائمة تُرتَّب بآخر رسالة لا بتاريخ الإنشاء: المحادثة التي وصل فيها سؤالٌ
 * قبل دقيقة أولى بالنظر من محادثةٍ فُتحت أمس وسكتت.
 */
class InboxController extends Controller
{
    public function __construct(private readonly OutboundMessageService $outbound) {}

    public function index(Request $request): View
    {
        $this->authorize('inbox.view');

        $filter = $request->string('filter')->value();

        $conversations = Conversation::query()
            ->with(['contact:id,channel_id,external_id,display_name,last_inbound_at', 'status:id,name,color', 'assignee:id,name'])
            // آخر رسالةٍ نصًّا في القائمة: فتحُ كل محادثةٍ لمعرفة آخر ما فيها
            // يجعل الشاشة عديمة الفائدة على عشرين محادثة.
            ->withCount(['messages as inbound_count' => fn ($q) => $q->where('direction', Message::IN)])
            ->when($filter === 'handed_off', fn ($q) => $q->handedOff())
            ->when($filter === 'ai', fn ($q) => $q->where('ai_mode', Conversation::AI_ACTIVE))
            ->when($filter === 'mine', fn ($q) => $q->where('assigned_user_id', $request->user()?->id))
            ->orderByDesc('last_message_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.inbox.index', [
            'conversations' => $conversations,
            'filter' => $filter,
            'channels' => MessagingChannel::where('provider', 'whatsapp')->orderBy('id')->get(),
            // المفتاح العام يُعرض بجانب مفتاح القناة: قناةٌ «مشغّلة» والوكيل
            // مطفأ عامًّا تبدو عطلًا، والفرق لا يظهر إلّا بعرض الاثنين.
            'globallyEnabled' => (bool) config('ai_agent.enabled'),
        ]);
    }

    public function show(Conversation $conversation): View
    {
        $this->authorize('inbox.view');

        $conversation->load(['contact.channel', 'status', 'assignee:id,name', 'order:id,number,status,total']);

        return view('admin.inbox.show', [
            'conversation' => $conversation,
            'messages' => $conversation->messages()
                ->with('sender:id,name')
                ->orderBy('id')
                ->limit(200)
                ->get(),
            'statuses' => ConversationStatus::where('is_active', true)->orderBy('sort_order')->get(),
            'canReply' => $conversation->contact?->windowOpen() ?? false,
        ]);
    }

    /**
     * ردٌّ بشريّ.
     *
     * الكتابة بيدٍ **تُسكت الوكيل** عن المحادثة: موظفةٌ تكتب ووكيلٌ يردّ في
     * الخيط نفسه يُنتجان صوتين متناقضين أمام الزبون.
     */
    public function reply(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('inbox.reply');

        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);

        try {
            $this->outbound->sendText($conversation, $data['body'], 'agent', $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', collect($e->errors())->flatten()->first());
        }

        if ($conversation->ai_mode === Conversation::AI_ACTIVE) {
            $conversation->forceFill(['ai_mode' => Conversation::AI_PAUSED])->save();
        }

        return back()->with('success', __('أُرسلت الرسالة.'));
    }

    /** إسناد المحادثة إلى موظفة. */
    public function assign(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('inbox.assign');

        $data = $request->validate(['user_id' => ['nullable', 'integer', 'exists:users,id']]);

        $conversation->forceFill(['assigned_user_id' => $data['user_id'] ?? null])->save();

        return back()->with('success', __('حُدّث الإسناد.'));
    }

    /** تغيير حالة المحادثة — الحالات من قاعدة البيانات لا من الكود. */
    public function status(Request $request, Conversation $conversation): RedirectResponse
    {
        $this->authorize('inbox.assign');

        $data = $request->validate(['status_id' => ['required', 'integer', 'exists:conversation_statuses,id']]);

        $conversation->forceFill(['status_id' => $data['status_id']])->save();

        return back()->with('success', __('حُدّثت الحالة.'));
    }
}
