<x-app-layout :title="__('محادثة')">
    <x-admin.header
        :title="$conversation->contact?->display_name ?: $conversation->contact?->external_id"
        :description="__('محادثة واتساب — وما قاله الوكيل فيها.')"
        :breadcrumbs="[
            __('الرئيسية') => route('admin.dashboard'),
            __('الصندوق الموحّد') => route('admin.inbox.index'),
            __('محادثة') => null,
        ]" />

    <div class="grid gap-5 lg:grid-cols-3">
        {{-- الخيط --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="admin-card p-4">
                <div class="space-y-3 max-h-[32rem] overflow-y-auto admin-scroll pe-1">
                    @forelse ($messages as $message)
                        @php $inbound = $message->direction === \App\Modules\Messaging\Models\Message::IN; @endphp
                        <div class="flex {{ $inbound ? 'justify-start' : 'justify-end' }}">
                            <div class="max-w-[80%] rounded-2xl px-3.5 py-2 text-sm ring-1
                                        {{ $inbound
                                           ? 'bg-white text-gray-800 ring-gray-200'
                                           : ($message->sender_type === 'ai'
                                              ? 'bg-emerald-50 text-emerald-900 ring-emerald-200'
                                              : 'bg-sky-50 text-sky-900 ring-sky-200') }}">
                                <p class="whitespace-pre-wrap break-words">{{ $message->body ?: '—' }}</p>

                                <div class="mt-1 flex items-center gap-2 text-[10px] text-gray-400">
                                    {{-- من كتبها: الفرق بين الوكيل والموظفة يجب أن يُقرأ بلا تخمين. --}}
                                    <span>
                                        @if ($inbound)
                                            {{ __('الزبون') }}
                                        @elseif ($message->sender_type === 'ai')
                                            {{ __('الوكيل') }}
                                        @else
                                            {{ $message->sender?->name ?? __('موظفة') }}
                                        @endif
                                    </span>
                                    <span>{{ $message->sent_at?->format('H:i · Y-m-d') }}</span>

                                    {{-- الفشل يُعرض في الخيط لا في سجلٍّ بعيد: رسالةٌ لم تصل
                                         يجب أن تُرى حيث يُقرأ الحوار. --}}
                                    @if ($message->delivery_status === 'failed')
                                        <span class="text-rose-600">{{ __('تعذّر الإرسال') }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @empty
                        <p class="py-8 text-center text-sm text-gray-400">{{ __('لا رسائل بعد.') }}</p>
                    @endforelse
                </div>
            </div>

            {{-- الردّ اليدوي --}}
            @can('inbox.reply')
                <div class="admin-card p-4">
                    @if ($canReply)
                        <form method="POST" action="{{ route('admin.inbox.reply', $conversation) }}" class="space-y-3">
                            @csrf
                            <textarea name="body" rows="3" required maxlength="4000"
                                      placeholder="{{ __('اكتب ردّك للزبون…') }}"
                                      class="w-full rounded-lg border-gray-300 text-sm focus:border-emerald-500 focus:ring-emerald-500"></textarea>
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-xs text-gray-500">
                                    {{ __('الكتابة بيدك توقف الوكيل عن هذه المحادثة — صوتان في خيطٍ واحد يربكان الزبون.') }}
                                </p>
                                <button type="submit" class="btn-primary btn-sm">{{ __('إرسال') }}</button>
                            </div>
                        </form>
                    @else
                        {{--
                            نافذة الأربع والعشرين ساعة: خارجها ترفض واتساب النصّ الحرّ،
                            وإخفاءُ السبب يجعل الفشل يبدو عطلًا في النظام.
                        --}}
                        <p class="text-sm text-amber-700 bg-amber-50 ring-1 ring-amber-200 rounded-lg px-3 py-2">
                            {{ __('مضى أكثر من 24 ساعة على آخر رسالة من الزبون — واتساب لا يسمح بنصٍّ حرّ الآن، ويلزم قالب معتمَد.') }}
                        </p>
                    @endif
                </div>
            @endcan
        </div>

        {{-- اللوحة الجانبية --}}
        <div class="space-y-4">
            <div class="admin-card p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-gray-500">{{ __('الوكيل') }}</span>
                    <x-admin.agent-mode :mode="$conversation->ai_mode" :reason="$conversation->handoff_reason" />
                </div>

                @if ($conversation->handoff_reason)
                    <p class="text-xs text-gray-500">{{ __('سبب التحويل:') }} {{ $conversation->handoff_reason }}</p>
                @endif

                @can('ai_agent.handoff')
                    <div class="flex flex-wrap gap-2 pt-1">
                        @if ($conversation->ai_mode === \App\Modules\Messaging\Models\Conversation::AI_ACTIVE)
                            <form method="POST" action="{{ route('admin.inbox.conversations.handoff', $conversation) }}">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm">{{ __('استلام المحادثة') }}</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.inbox.conversations.resume', $conversation) }}">
                                @csrf
                                <button type="submit" class="btn-secondary btn-sm">{{ __('إعادة الوكيل') }}</button>
                            </form>
                        @endif
                    </div>
                @endcan
            </div>

            <div class="admin-card p-4 space-y-3">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm text-gray-500">{{ __('الهاتف') }}</span>
                    <span class="text-sm font-mono">{{ $conversation->contact?->external_id }}</span>
                </div>

                @if ($conversation->order)
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-sm text-gray-500">{{ __('الطلب') }}</span>
                        <a href="{{ route('admin.sales.orders.index', ['search' => $conversation->order->number]) }}"
                           class="text-sm text-emerald-600 hover:underline">{{ $conversation->order->number }}</a>
                    </div>
                    <p class="text-xs text-gray-500">
                        {{ __('أنشأه الوكيل كمسودّة — يُراجَع ويُؤكَّد من شاشة الطلبات.') }}
                    </p>
                @endif

                @can('inbox.assign')
                    <form method="POST" action="{{ route('admin.inbox.status', $conversation) }}" class="pt-1">
                        @csrf
                        <label class="block text-xs text-gray-500 mb-1">{{ __('الحالة') }}</label>
                        <div class="flex gap-2">
                            <select name="status_id" class="flex-1 rounded-lg border-gray-300 text-sm">
                                @foreach ($statuses as $status)
                                    <option value="{{ $status->id }}" @selected($conversation->status_id === $status->id)>{{ $status->name }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn-secondary btn-sm">{{ __('حفظ') }}</button>
                        </div>
                    </form>
                @endcan
            </div>
        </div>
    </div>
</x-app-layout>
