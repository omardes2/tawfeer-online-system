<x-app-layout :title="__('الصندوق الموحّد')">
    <x-admin.header
        :title="__('الصندوق الموحّد')"
        :description="__('محادثات واتساب — وما قاله الوكيل فيها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('الصندوق الموحّد') => null]" />

    {{--
        مفتاح الوكيل أعلى الشاشة لا في الإعدادات: من يرى ردًّا سيّئًا يريد
        إيقافه من حيث رآه، لا أن يبحث عن الشاشة التي فيها المفتاح.
    --}}
    @can('ai_agent.toggle')
        <div class="admin-card p-4 mb-5">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-sm font-semibold text-gray-700">{{ __('وكيل المبيعات الذكي') }}</span>

                @unless ($globallyEnabled)
                    {{-- المفتاح العام مطفأ: قناةٌ «مشغّلة» تحته لا تردّ، والفرق لا يظهر إلّا بقوله. --}}
                    <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-amber-50 text-amber-700 ring-amber-200">
                        {{ __('مطفأ عامًّا من الإعدادات — لا يردّ على أي قناة') }}
                    </span>
                @endunless

                <div class="flex flex-wrap items-center gap-2 ms-auto">
                    @forelse ($channels as $channel)
                        <form method="POST" action="{{ route('admin.inbox.channels.toggle_ai', $channel) }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1 transition
                                           {{ $channel->ai_enabled
                                              ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'
                                              : 'bg-gray-50 text-gray-500 ring-gray-200 hover:bg-gray-100' }}">
                                <span class="w-2 h-2 rounded-full {{ $channel->ai_enabled ? 'bg-emerald-500' : 'bg-gray-400' }}"></span>
                                {{ $channel->name }}
                                <span class="text-xs">{{ $channel->ai_enabled ? __('يعمل — اضغط للإيقاف') : __('متوقّف — اضغط للتشغيل') }}</span>
                            </button>
                        </form>
                    @empty
                        <span class="text-xs text-gray-400">{{ __('لا قناة واتساب مضبوطة بعد.') }}</span>
                    @endforelse
                </div>
            </div>
        </div>
    @endcan

    <div class="flex flex-wrap gap-2 mb-4">
        @foreach ([
            '' => __('الكل'),
            'handed_off' => __('محوّلة إلى موظفة'),
            'ai' => __('الوكيل يردّ'),
            'mine' => __('المسندة إليّ'),
        ] as $key => $label)
            <a href="{{ route('admin.inbox.index', $key ? ['filter' => $key] : []) }}"
               class="rounded-lg px-3 py-1.5 text-sm ring-1 transition
                      {{ $filter === $key ? 'bg-emerald-600 text-white ring-emerald-600' : 'bg-white text-gray-600 ring-gray-200 hover:bg-gray-50' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="admin-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>{{ __('الزبون') }}</th>
                        <th>{{ __('الحالة') }}</th>
                        <th>{{ __('الوكيل') }}</th>
                        <th>{{ __('المسندة إلى') }}</th>
                        <th class="text-start">{{ __('رسائل الزبون') }}</th>
                        <th>{{ __('آخر رسالة') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($conversations as $conversation)
                        <tr>
                            <td data-label="{{ __('الزبون') }}" class="font-medium">
                                <a href="{{ route('admin.inbox.show', $conversation) }}" class="text-emerald-600 hover:underline">
                                    {{ $conversation->contact?->display_name ?: $conversation->contact?->external_id }}
                                </a>
                                @if ($conversation->contact?->display_name)
                                    <span class="block text-[11px] text-gray-400 font-mono">{{ $conversation->contact->external_id }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('الحالة') }}">
                                <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 bg-gray-50 text-gray-600 ring-gray-200">
                                    {{ $conversation->status?->name ?? '—' }}
                                </span>
                            </td>
                            <td data-label="{{ __('الوكيل') }}">
                                <x-admin.agent-mode :mode="$conversation->ai_mode" :reason="$conversation->handoff_reason" />
                            </td>
                            <td data-label="{{ __('المسندة إلى') }}" class="text-gray-600">
                                {{ $conversation->assignee?->name ?? '—' }}
                            </td>
                            <td data-label="{{ __('رسائل الزبون') }}" class="text-start tabular-nums text-gray-600">
                                {{ $conversation->inbound_count }}
                            </td>
                            <td data-label="{{ __('آخر رسالة') }}" class="text-gray-500 whitespace-nowrap text-xs">
                                {{ $conversation->last_message_at?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6">
                                <x-admin.empty-state
                                    :title="__('لا محادثات بعد')"
                                    :description="__('تظهر هنا كل محادثة واتساب تصل إلى رقم المتجر — وما ردّ به الوكيل عليها.')" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">{{ $conversations->links() }}</div>
</x-app-layout>
