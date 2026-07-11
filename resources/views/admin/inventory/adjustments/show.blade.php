<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-gray-800">{{ __('تسوية') }} {{ $adjustment->number }}</h2></x-slot>
    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
            <x-admin.flash />
            <div class="flex flex-wrap gap-4 text-sm">
                <span>{{ __('المستودع') }}: <b>{{ $adjustment->warehouse?->name }}</b></span>
                <span>{{ __('النوع') }}: {{ $adjustment->type }}</span>
                <span>{{ __('الحالة') }}: <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-700">{{ $adjustment->status }}</span></span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm text-right">
                    <thead class="text-gray-500 border-b"><tr>
                        <th class="py-2 px-3 font-medium">SKU</th>
                        <th class="py-2 px-3 font-medium">{{ __('قبل') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('العدّ') }}</th>
                        <th class="py-2 px-3 font-medium">{{ __('الفرق') }}</th>
                    </tr></thead>
                    <tbody class="divide-y">
                        @foreach ($adjustment->items as $item)
                            <tr>
                                <td class="py-2 px-3 text-gray-500">{{ $item->variant?->sku }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_before, '0'), '.') }}</td>
                                <td class="py-2 px-3">{{ rtrim(rtrim($item->qty_counted, '0'), '.') }}</td>
                                <td class="py-2 px-3 font-medium {{ $item->qty_diff < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ rtrim(rtrim($item->qty_diff, '0'), '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap gap-2 pt-2 border-t">
                @if (in_array($adjustment->status, ['draft', 'pending_approval']))
                    @can('approve', $adjustment)
                        <form method="POST" action="{{ route('admin.inventory.adjustments.approve', $adjustment) }}">@csrf<button class="px-4 py-2 bg-blue-600 text-white text-sm rounded-md hover:bg-blue-700">{{ __('اعتماد') }}</button></form>
                    @endcan
                @endif
                @if ($adjustment->status === 'approved')
                    @can('post', $adjustment)
                        <form method="POST" action="{{ route('admin.inventory.adjustments.post', $adjustment) }}" onsubmit="return confirm('{{ __('ترحيل وتطبيق على المخزون؟') }}')">@csrf<button class="px-4 py-2 bg-emerald-600 text-white text-sm rounded-md hover:bg-emerald-700">{{ __('ترحيل') }}</button></form>
                    @endcan
                @endif
                @if ($adjustment->status !== 'posted')
                    @can('delete', $adjustment)
                        <form method="POST" action="{{ route('admin.inventory.adjustments.destroy', $adjustment) }}" onsubmit="return confirm('{{ __('حذف؟') }}')">@csrf @method('DELETE')<button class="px-4 py-2 bg-rose-100 text-rose-700 text-sm rounded-md hover:bg-rose-200">{{ __('حذف') }}</button></form>
                    @endcan
                @endif
                <a href="{{ route('admin.inventory.adjustments.index') }}" class="px-4 py-2 bg-gray-100 text-gray-700 text-sm rounded-md">{{ __('رجوع') }}</a>
            </div>
        </div>
    </div>
</x-app-layout>
