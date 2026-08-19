<x-app-layout :title="__('قوائم أسعار التجّار')">
    <x-admin.header
        :title="__('قوائم أسعار التجّار')"
        :description="__('طبقة سعرٍ تُسنَد إلى أشخاص بعينهم — من له قائمة يشتري بأسعارها، ويكون ربحه فرقَ ما بين سعر بيعه وسعرها.')"
        :breadcrumbs="[__('الرئيسية') => route('admin.dashboard'), __('المنتجات') => null, __('قوائم أسعار التجّار') => null]">
        @can('catalog.price_lists.manage')
            <a href="{{ route('admin.price_lists.create') }}" class="btn-primary btn-sm">{{ __('قائمة جديدة') }}</a>
        @endcan
    </x-admin.header>

    <x-admin.flash />

    <x-admin.table stack>
        <thead>
            <tr>
                <th>{{ __('القائمة') }}</th>
                <th>{{ __('ترث من') }}</th>
                <th class="text-center">{{ __('الأصناف المسعَّرة') }}</th>
                <th class="text-center">{{ __('المرتبطون') }}</th>
                <th class="text-center">{{ __('الحالة') }}</th>
                <th class="text-center">{{ __('إجراء') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lists as $list)
                <tr>
                    <td data-label="{{ __('القائمة') }}" class="font-medium text-gray-800">
                        {{ $list->name }}
                        @if ($list->code)
                            <span class="block text-[11px] text-gray-400 font-mono">{{ $list->code }}</span>
                        @endif
                        @if ($list->notes)
                            <span class="block text-[11px] text-gray-500">{{ $list->notes }}</span>
                        @endif
                    </td>
                    <td data-label="{{ __('ترث من') }}" class="text-sm text-gray-500">
                        {{ $list->parent?->name ?? '—' }}
                    </td>
                    <td data-label="{{ __('الأصناف المسعَّرة') }}" class="text-center tabular-nums">{{ $list->items_count }}</td>
                    <td data-label="{{ __('المرتبطون') }}" class="text-center tabular-nums">{{ $list->users_count }}</td>
                    <td data-label="{{ __('الحالة') }}" class="text-center">
                        <span class="inline-flex px-2 py-0.5 rounded-full text-[11px] ring-1 {{ $list->is_active ? 'bg-emerald-50 text-emerald-700 ring-emerald-200' : 'bg-gray-100 text-gray-500 ring-gray-200' }}">
                            {{ $list->is_active ? __('نشطة') : __('معطَّلة') }}
                        </span>
                    </td>
                    <td data-label="{{ __('إجراء') }}" class="text-center">
                        @can('catalog.price_lists.manage')
                            <div class="flex items-center justify-center gap-3">
                                <a href="{{ route('admin.price_lists.edit', $list) }}" class="text-emerald-600 hover:underline text-sm">{{ __('تعديل') }}</a>
                                <x-admin.confirm
                                    :action="route('admin.price_lists.destroy', $list)"
                                    :trigger="__('حذف')"
                                    :message="__('حذف «:n»؟ سيعود :u مستخدمًا إلى سعر الجملة، وترتفع أسعار شرائهم.', ['n' => $list->name, 'u' => $list->users_count])" />
                            </div>
                        @else
                            <span class="text-gray-300">—</span>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="!p-0">
                    <x-admin.empty-state
                        :title="__('لا قوائم بعد')"
                        :description="__('أنشئ قائمة «أسعار تجّار»، ضَع فيها الأصناف وأسعارها، ثم أسنِدها لمن تشاء من صفحة المستخدم.')" />
                </td></tr>
            @endforelse
        </tbody>
    </x-admin.table>

    <div class="mt-4 rounded-xl bg-gray-50 ring-1 ring-gray-200 p-4 text-xs leading-6 text-gray-600">
        <p class="font-semibold text-gray-700 mb-1">{{ __('كيف تعمل') }}</p>
        <p>{{ __('سعر الشراء يُحسم بالترتيب: سعر قائمة المستخدم، ثم سعر القائمة التي ترث منها، ثم سعر الجملة، ثم التكلفة. ومن لا قائمة له لا يتغيّر عليه شيء.') }}</p>
        <p class="mt-1">{{ __('لتخصيص تاجرٍ بعينه: أنشئ له قائمةً ترث من قائمة التجّار العامّة، وضع فيها الأصناف المختلفة عليه وحدها — والباقي يرثه تلقائيًّا بلا تكرار.') }}</p>
        <p class="mt-1">{{ __('السعر المحسوم يُجمَّد على بند الطلب وقت البيع، فلا يتغيّر ربحُ طلبٍ مضى بتعديل القائمة اليوم.') }}</p>
    </div>
</x-app-layout>
